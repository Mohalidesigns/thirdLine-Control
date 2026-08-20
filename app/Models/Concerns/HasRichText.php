<?php

namespace App\Models\Concerns;

use App\Support\RichText;

/**
 * Opt-in Editor.js storage. A model declares
 *
 *     protected array $richText = ['description', 'root_cause'];
 *
 * and for each field gains a fillable `{field}_rich` array attribute
 * holding the sanitised Editor.js document. On save, the original plain
 * column is re-derived from the document so search, filters, exports and
 * report generation keep reading the column they always read.
 */
trait HasRichText
{
    public function initializeHasRichText(): void
    {
        foreach ($this->richTextFields() as $field) {
            $this->fillable[] = "{$field}_rich";
            $this->casts["{$field}_rich"] = 'array';
        }
    }

    public static function bootHasRichText(): void
    {
        static::saving(function ($model) {
            foreach ($model->richTextFields() as $field) {
                $doc = $model->{"{$field}_rich"};

                if ($doc === null) {
                    continue;
                }

                if (! RichText::isDoc($doc)) {
                    $model->{"{$field}_rich"} = null;

                    continue;
                }

                $clean = RichText::sanitize($doc, $model->richTextPreset($field));
                $model->{"{$field}_rich"} = $clean;
                // The plain column mirrors the document — one source of truth.
                $model->{$field} = RichText::toPlain($clean);
            }
        });
    }

    /** @return array<int, string> */
    public function richTextFields(): array
    {
        return property_exists($this, 'richText') ? $this->richText : [];
    }

    public function richTextPreset(string $field): string
    {
        return (property_exists($this, 'richTextPresets') ? $this->richTextPresets : [])[$field] ?? 'default';
    }
}
