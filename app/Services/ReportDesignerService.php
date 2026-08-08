<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\ReportReadyNotification;
use App\Reports\Engines\CsvEngine;
use App\Reports\Engines\ExcelEngine;
use App\Reports\Engines\HtmlEngine;
use App\Reports\Engines\PdfEngine;
use App\Reports\Engines\PowerPointEngine;
use App\Reports\Engines\ReportEngine;
use App\Reports\Engines\WordEngine;
use App\Reports\ReportSectionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generation, storage and distribution of designed reports (13.3).
 *
 * A run is a record, not a side effect: the parameters, the checksum, the
 * recipients and the expiry are all persisted, so "who received which
 * numbers, and can we prove the file has not changed since" has an answer.
 */
class ReportDesignerService
{
    /** @var array<string, class-string<ReportEngine>> */
    private const ENGINES = [
        'pdf' => PdfEngine::class,
        'xlsx' => ExcelEngine::class,
        'docx' => WordEngine::class,
        'pptx' => PowerPointEngine::class,
        'html' => HtmlEngine::class,
        'csv' => CsvEngine::class,
    ];

    public function __construct(
        private ReportSectionResolver $resolver,
        private NotificationDispatcher $dispatcher,
    ) {}

    public function engine(string $format): ReportEngine
    {
        $engine = self::ENGINES[$format] ?? null;

        if (! $engine) {
            throw ValidationException::withMessages([
                'output_format' => "'{$format}' is not an output format this platform writes.",
            ]);
        }

        return app($engine);
    }

    /** @return array<int, string> */
    public function formats(): array
    {
        return array_keys(self::ENGINES);
    }

    public function createDefinition(array $data, User $user): ReportDefinition
    {
        $definition = ReportDefinition::create($data + [
            'code' => $data['code'] ?? Str::upper(Str::random(3)).'-'.now()->format('ymdHis'),
            'owner_id' => $user->id,
            'version_no' => 1,
            'is_system' => false,
            'is_active' => true,
        ]);

        $definition->auditAction('report_definition.created', null, ['code' => $definition->code]);

        return $definition;
    }

    /**
     * Editing a definition invalidates its approval. A report that was signed
     * off with three sections and now has five has not been signed off.
     */
    public function updateDefinition(ReportDefinition $definition, array $data): ReportDefinition
    {
        $before = $definition->only(['name', 'sections', 'parameters', 'output_formats', 'confidentiality']);

        $definition->fill($data);

        if ($definition->isDirty(['sections', 'parameters', 'output_formats', 'confidentiality', 'styling'])
            && $definition->approved_at !== null) {
            $definition->approved_by = null;
            $definition->approved_at = null;
            $definition->version_no++;
        }

        $definition->save();
        $definition->auditAction('report_definition.updated', $before, $definition->only(array_keys($before)));

        return $definition;
    }

    /** Maker-checker on the document itself: the author never approves it. */
    public function approveDefinition(ReportDefinition $definition, User $approver): ReportDefinition
    {
        if ($definition->owner_id === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A report definition must be approved by someone other than its author.',
            ]);
        }

        $definition->forceFill([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        $definition->auditAction('report_definition.approved', null, ['approved_by' => $approver->id]);

        return $definition;
    }

    /**
     * Generate one report. The whole pipeline is recorded on the run, and a
     * failure is recorded too — a report that silently did not generate is
     * how a board pack goes missing on the morning of the meeting.
     */
    public function run(
        ReportDefinition $definition,
        User $user,
        string $format,
        array $parameters = [],
        ?ReportSchedule $schedule = null,
    ): ReportRun {
        if (! $definition->supportsFormat($format)) {
            throw ValidationException::withMessages([
                'output_format' => "'{$definition->name}' is not published in {$format} format.",
            ]);
        }

        return $this->generate($definition, $user, $format, $parameters, $schedule, null);
    }

    /**
     * Generate from a document that was built elsewhere — the submission
     * packs assemble their own content and still want the run record, the
     * checksum and the expiring link this pipeline gives everything else.
     */
    public function runWithDocument(
        ReportDefinition $definition,
        User $user,
        string $format,
        array $document,
        array $parameters = [],
    ): ReportRun {
        return $this->generate($definition, $user, $format, $parameters, null, $document);
    }

    private function generate(
        ReportDefinition $definition,
        User $user,
        string $format,
        array $parameters,
        ?ReportSchedule $schedule,
        ?array $document,
    ): ReportRun {
        $run = ReportRun::create([
            'report_definition_id' => $definition->id,
            'schedule_id' => $schedule?->id,
            'run_ref' => ReportRun::nextReference('RPT', true, 'run_ref'),
            'parameters' => $parameters,
            'requested_by' => $user->id,
            'started_at' => now(),
            'status' => 'Running',
            'output_format' => $format,
            'download_token' => Str::random(48),
            'expires_at' => now()->addHours((int) config('dashboards.report_link_ttl_hours')),
        ]);

        try {
            $engine = $this->engine($format);
            $document ??= $this->resolver->resolve($definition, $user, $parameters);
            $contents = $engine->render($document);

            $path = sprintf(
                'reports/%d/%s.%s',
                $user->tenant_id,
                $run->run_ref.'-'.Str::slug($definition->code),
                $engine->extension(),
            );

            Storage::disk(config('dashboards.report_disk'))->put($path, $contents);

            $run->forceFill([
                'status' => 'Completed',
                'completed_at' => now(),
                'output_path' => $path,
                'file_size' => strlen($contents),
                'page_count' => $engine->pageCount($document),
                'checksum' => hash('sha256', $contents),
            ])->save();

            $run->auditAction('report.generated', null, [
                'definition' => $definition->code,
                'format' => $format,
                'checksum' => $run->checksum,
            ]);
        } catch (\Throwable $e) {
            Log::error('Report generation failed', [
                'definition' => $definition->code,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            $run->forceFill([
                'status' => 'Failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();
        }

        return $run;
    }

    /**
     * Distribution respects the definition's confidentiality: a Board-classified
     * report only reaches recipients who could already open board material, and
     * everyone filtered out is recorded rather than silently dropped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function distribute(ReportRun $run, ReportSchedule $schedule): array
    {
        if ($run->status !== 'Completed') {
            return [];
        }

        $definition = $schedule->definition;
        $delivered = [];
        $withheld = [];

        foreach ($this->recipients($schedule) as $recipient) {
            if (! $this->mayReceive($recipient, $definition)) {
                $withheld[] = ['user_id' => $recipient->id, 'reason' => 'confidentiality'];

                continue;
            }

            $delivered[] = ['user_id' => $recipient->id, 'name' => $recipient->name, 'at' => now()->toIso8601String()];

            if (in_array($schedule->delivery_method, ['email', 'in_app'], true)) {
                $this->dispatcher->send(
                    $recipient,
                    'report.ready',
                    new ReportReadyNotification($run, $definition->name),
                );
            }
        }

        $run->forceFill(['distributed_to' => ['delivered' => $delivered, 'withheld' => $withheld]])->save();
        $run->auditAction('report.distributed', null, [
            'delivered' => count($delivered),
            'withheld' => count($withheld),
        ]);

        return $delivered;
    }

    /** @return Collection<int, User> */
    private function recipients(ReportSchedule $schedule)
    {
        $recipients = $schedule->recipients ?? [];

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($recipients) {
                $query->whereIn('id', array_filter($recipients['user_ids'] ?? []));

                if (! empty($recipients['role_names'])) {
                    $query->orWhereHas('roles', fn ($q) => $q->whereIn('name', $recipients['role_names']));
                }
            })
            ->get();
    }

    private function mayReceive(User $user, ReportDefinition $definition): bool
    {
        return match ($definition->confidentiality) {
            'Board' => $user->hasAnyRole(['Executive Viewer', 'Control Function Head', 'System Administrator']),
            'Confidential' => $user->can('export reports'),
            default => $user->can('view dashboards'),
        };
    }
}
