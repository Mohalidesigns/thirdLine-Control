<?php

namespace App\Reports\Pptx;

use RuntimeException;
use ZipArchive;

/**
 * A minimal, dependency-free PresentationML writer.
 *
 * Why this exists rather than phpoffice/phppresentation: that library pins
 * phpoffice/phpspreadsheet to ^4 or below, and every phpspreadsheet release
 * below 5 currently carries published security advisories. Installing it
 * would mean shipping a knowingly vulnerable dependency into a platform
 * whose whole purpose is control assurance, and downgrading the spreadsheet
 * engine the Phase 4 exports already depend on. Writing the twelve XML parts
 * a valid deck needs is the smaller risk, and it is deterministic.
 *
 * A .pptx is a ZIP of XML parts. This writer produces the minimum set an
 * Office-compatible reader requires: content types, package relationships,
 * document properties, a presentation part, one slide master, one blank
 * layout, a theme, and one part per slide.
 *
 * Units are EMU (English Metric Units): 914,400 per inch, 12,700 per point.
 */
class PresentationWriter
{
    /** 16:9 at 13.333in × 7.5in. */
    public const SLIDE_WIDTH = 12192000;

    public const SLIDE_HEIGHT = 6858000;

    public const EMU_PER_POINT = 12700;

    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    /** @var array<int, string> rendered slide XML, in order */
    private array $slides = [];

    private int $shapeId = 1;

    public function __construct(
        private string $title = 'Presentation',
        private string $author = 'Atheris Control',
        private string $createdAt = '1970-01-01T00:00:00Z',
    ) {}

    public function slideCount(): int
    {
        return count($this->slides);
    }

    /**
     * Add a slide from an ordered list of blocks. Each block is one of:
     *   ['kind' => 'title',  'text' => …, 'size' => pt, 'colour' => 'RRGGBB', 'align' => 'l|ctr']
     *   ['kind' => 'text',   'lines' => [ ['text' =>, 'size' =>, 'bold' =>, 'colour' =>, 'bullet' => bool] ]]
     *   ['kind' => 'bars',   'rows' => [ ['label' =>, 'value' =>, 'display' =>, 'colour' =>] ], 'max' => float]
     *   ['kind' => 'table',  'columns' => [...], 'rows' => [[...]]]
     * Blocks stack down the slide; each declares its own height in points.
     */
    public function addSlide(array $blocks, string $background = 'FFFFFF'): void
    {
        $this->shapeId = 1;
        $shapes = '';
        $y = $this->pt(48);

        foreach ($blocks as $block) {
            [$xml, $consumed] = $this->block($block, $y);
            $shapes .= $xml;
            $y += $consumed;
        }

        $this->slides[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld '.self::NS.'><p:cSld>'
            .'<p:bg><p:bgPr><a:solidFill><a:srgbClr val="'.$background.'"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            .'<p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .$shapes
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    /** @return string the .pptx package as raw bytes */
    public function save(): string
    {
        if ($this->slides === []) {
            $this->addSlide([['kind' => 'title', 'text' => $this->title]]);
        }

        $path = tempnam(sys_get_temp_dir(), 'pptx');

        if ($path === false) {
            throw new RuntimeException('Could not allocate a temporary file for the presentation.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the presentation archive for writing.');
        }

        foreach ($this->parts() as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /** @return array<string, string> part name => XML */
    public function parts(): array
    {
        $count = count($this->slides);
        $parts = [
            '[Content_Types].xml' => $this->contentTypes($count),
            '_rels/.rels' => $this->packageRels(),
            'docProps/core.xml' => $this->coreProps(),
            'docProps/app.xml' => $this->appProps($count),
            'ppt/presentation.xml' => $this->presentation($count),
            'ppt/_rels/presentation.xml.rels' => $this->presentationRels($count),
            'ppt/slideMasters/slideMaster1.xml' => $this->slideMaster(),
            'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $this->slideMasterRels(),
            'ppt/slideLayouts/slideLayout1.xml' => $this->slideLayout(),
            'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $this->slideLayoutRels(),
            'ppt/theme/theme1.xml' => $this->theme(),
        ];

        foreach ($this->slides as $index => $slide) {
            $number = $index + 1;
            $parts["ppt/slides/slide{$number}.xml"] = $slide;
            $parts["ppt/slides/_rels/slide{$number}.xml.rels"] = $this->slideRels();
        }

        return $parts;
    }

    // ── Blocks ───────────────────────────────────────────────────────────

    /** @return array{0: string, 1: int} XML and the vertical space it used */
    private function block(array $block, int $y): array
    {
        return match ($block['kind'] ?? 'text') {
            'title' => $this->titleBlock($block, $y),
            'bars' => $this->barsBlock($block, $y),
            'table' => $this->tableBlock($block, $y),
            default => $this->textBlock($block, $y),
        };
    }

    private function titleBlock(array $block, int $y): array
    {
        $size = (int) ($block['size'] ?? 30);
        $height = $this->pt($size * 1.6);

        $xml = $this->textShape(
            $this->pt(48), $y, self::SLIDE_WIDTH - $this->pt(96), $height,
            [[
                'text' => (string) $block['text'],
                'size' => $size,
                'bold' => true,
                'colour' => $block['colour'] ?? '0B1F3A',
            ]],
            $block['align'] ?? 'l',
        );

        return [$xml, $height + $this->pt(12)];
    }

    private function textBlock(array $block, int $y): array
    {
        $lines = $block['lines'] ?? [];

        if ($lines === []) {
            return ['', 0];
        }

        $height = 0;

        foreach ($lines as $line) {
            $height += $this->pt(((int) ($line['size'] ?? 14)) * 1.5);
        }

        $xml = $this->textShape(
            $this->pt(48), $y, self::SLIDE_WIDTH - $this->pt(96), $height, $lines,
        );

        return [$xml, $height + $this->pt(10)];
    }

    /**
     * A horizontal bar chart drawn as shapes: label, a track-free rounded
     * bar, and the value beside it. Rounded at the data end, square at the
     * baseline, capped thickness — the same mark spec the on-screen charts
     * use, so the deck and the dashboard read as one system.
     */
    private function barsBlock(array $block, int $y): array
    {
        $rows = array_slice($block['rows'] ?? [], 0, 8);

        if ($rows === []) {
            return ['', 0];
        }

        $max = max(0.0001, (float) ($block['max'] ?? max(array_map(
            fn (array $row) => (float) $row['value'], $rows,
        ))));

        $labelWidth = $this->pt(150);
        $valueWidth = $this->pt(80);
        $trackX = $this->pt(48) + $labelWidth + $this->pt(8);
        $trackWidth = self::SLIDE_WIDTH - $this->pt(96) - $labelWidth - $valueWidth - $this->pt(16);
        $rowHeight = $this->pt(30);
        $barHeight = $this->pt(16);

        $xml = '';
        $cursor = $y;

        foreach ($rows as $row) {
            $width = max($this->pt(2), (int) round($trackWidth * ((float) $row['value'] / $max)));

            $xml .= $this->textShape(
                $this->pt(48), $cursor + $this->pt(2), $labelWidth, $rowHeight,
                [['text' => (string) $row['label'], 'size' => 11, 'colour' => '2D3748']],
            );

            $xml .= $this->rectShape(
                $trackX, $cursor + $this->pt(4), $width, $barHeight,
                $row['colour'] ?? '2A78D6',
            );

            $xml .= $this->textShape(
                $trackX + $trackWidth + $this->pt(8), $cursor + $this->pt(2), $valueWidth, $rowHeight,
                [['text' => (string) ($row['display'] ?? $row['value']), 'size' => 11, 'bold' => true, 'colour' => '2D3748']],
            );

            $cursor += $rowHeight;
        }

        return [$xml, ($cursor - $y) + $this->pt(12)];
    }

    private function tableBlock(array $block, int $y): array
    {
        $columns = $block['columns'] ?? [];
        $rows = array_slice($block['rows'] ?? [], 0, 10);

        if ($columns === []) {
            return ['', 0];
        }

        $width = self::SLIDE_WIDTH - $this->pt(96);
        $columnWidth = (int) floor($width / count($columns));
        $rowHeight = $this->pt(22);
        $height = $rowHeight * (count($rows) + 1);

        $grid = '';

        foreach ($columns as $index => $column) {
            // The last column absorbs the rounding remainder so the grid
            // always sums to the frame width.
            $grid .= '<a:gridCol w="'.($index === count($columns) - 1
                ? $width - $columnWidth * (count($columns) - 1)
                : $columnWidth).'"/>';
        }

        $body = '<a:tr h="'.$rowHeight.'">';

        foreach ($columns as $column) {
            $body .= $this->tableCell((string) $column, true);
        }

        $body .= '</a:tr>';

        foreach ($rows as $row) {
            $body .= '<a:tr h="'.$rowHeight.'">';

            foreach (array_slice(array_values($row), 0, count($columns)) as $cell) {
                $body .= $this->tableCell((string) ($cell ?? '—'), false);
            }

            $body .= '</a:tr>';
        }

        $id = $this->shapeId++;

        $xml = '<p:graphicFrame><p:nvGraphicFramePr>'
            .'<p:cNvPr id="'.($id + 1).'" name="Table '.$id.'"/>'
            .'<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/>'
            .'</p:nvGraphicFramePr>'
            .'<p:xfrm><a:off x="'.$this->pt(48).'" y="'.$y.'"/><a:ext cx="'.$width.'" cy="'.$height.'"/></p:xfrm>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'
            .'<a:tbl><a:tblPr firstRow="1" bandRow="1"/><a:tblGrid>'.$grid.'</a:tblGrid>'
            .$body
            .'</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';

        return [$xml, $height + $this->pt(12)];
    }

    private function tableCell(string $text, bool $header): string
    {
        return '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr algn="l"/><a:r>'
            .'<a:rPr lang="en-GB" sz="'.($header ? 1000 : 950).'"'.($header ? ' b="1"' : '').' dirty="0">'
            .'<a:solidFill><a:srgbClr val="'.($header ? 'FFFFFF' : '2D3748').'"/></a:solidFill>'
            .'<a:latin typeface="Calibri"/></a:rPr>'
            .'<a:t>'.$this->escape($text).'</a:t></a:r></a:p></a:txBody>'
            .'<a:tcPr marL="45720" marR="45720" marT="27432" marB="27432" anchor="ctr">'
            .'<a:solidFill><a:srgbClr val="'.($header ? '0B1F3A' : 'FFFFFF').'"/></a:solidFill>'
            .'</a:tcPr></a:tc>';
    }

    // ── Shape primitives ─────────────────────────────────────────────────

    private function textShape(int $x, int $y, int $cx, int $cy, array $lines, string $align = 'l'): string
    {
        $id = ++$this->shapeId;
        $paragraphs = '';

        foreach ($lines as $line) {
            $bullet = ($line['bullet'] ?? false)
                ? '<a:buFont typeface="Arial"/><a:buChar char="&#8226;"/>'
                : '<a:buNone/>';

            $paragraphs .= '<a:p><a:pPr algn="'.$align.'" marL="'.(($line['bullet'] ?? false) ? 171450 : 0).'" '
                .'indent="'.(($line['bullet'] ?? false) ? -171450 : 0).'">'.$bullet.'</a:pPr>'
                .'<a:r><a:rPr lang="en-GB" sz="'.((int) ($line['size'] ?? 14) * 100).'"'
                .(($line['bold'] ?? false) ? ' b="1"' : '').' dirty="0">'
                .'<a:solidFill><a:srgbClr val="'.($line['colour'] ?? '2D3748').'"/></a:solidFill>'
                .'<a:latin typeface="Calibri"/></a:rPr>'
                .'<a:t>'.$this->escape((string) ($line['text'] ?? '')).'</a:t></a:r></a:p>';
        }

        return '<p:sp><p:nvSpPr>'
            .'<p:cNvPr id="'.$id.'" name="Text '.$id.'"/>'
            .'<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.max(1, $cx).'" cy="'.max(1, $cy).'"/></a:xfrm>'
            .'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            .'<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0"><a:normAutofit/></a:bodyPr>'
            .'<a:lstStyle/>'.$paragraphs.'</p:txBody></p:sp>';
    }

    private function rectShape(int $x, int $y, int $cx, int $cy, string $colour): string
    {
        $id = ++$this->shapeId;

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Bar '.$id.'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.max(1, $cx).'" cy="'.max(1, $cy).'"/></a:xfrm>'
            .'<a:prstGeom prst="round2SameRect"><a:avLst>'
            .'<a:gd name="adj1" fmla="val 0"/><a:gd name="adj2" fmla="val 0"/></a:avLst></a:prstGeom>'
            .'<a:solidFill><a:srgbClr val="'.$colour.'"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr>'
            .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-GB"/></a:p></p:txBody></p:sp>';
    }

    private function pt(float $points): int
    {
        return (int) round($points * self::EMU_PER_POINT);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ── Package parts ────────────────────────────────────────────────────

    private function contentTypes(int $slides): string
    {
        $overrides = '';

        for ($i = 1; $i <= $slides; $i++) {
            $overrides .= '<Override PartName="/ppt/slides/slide'.$i.'.xml" '
                .'ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .$overrides
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function packageRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function coreProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->escape($this->title).'</dc:title>'
            .'<dc:creator>'.$this->escape($this->author).'</dc:creator>'
            .'<cp:lastModifiedBy>'.$this->escape($this->author).'</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$this->createdAt.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$this->createdAt.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appProps(int $slides): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Atheris Control</Application>'
            .'<Slides>'.$slides.'</Slides>'
            .'<Company>'.$this->escape($this->author).'</Company>'
            .'</Properties>';
    }

    private function presentation(int $slides): string
    {
        $ids = '';

        for ($i = 1; $i <= $slides; $i++) {
            $ids .= '<p:sldId id="'.(255 + $i).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation '.self::NS.' saveSubsetFonts="1">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            .'<p:sldIdLst>'.$ids.'</p:sldIdLst>'
            .'<p:sldSz cx="'.self::SLIDE_WIDTH.'" cy="'.self::SLIDE_HEIGHT.'"/>'
            .'<p:notesSz cx="'.self::SLIDE_HEIGHT.'" cy="'.self::SLIDE_WIDTH.'"/>'
            .'</p:presentation>';
    }

    private function presentationRels(int $slides): string
    {
        $rels = '<Relationship Id="rId1" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" '
            .'Target="slideMasters/slideMaster1.xml"/>';

        for ($i = 1; $i <= $slides; $i++) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" '
                .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" '
                .'Target="slides/slide'.$i.'.xml"/>';
        }

        $rels .= '<Relationship Id="rId'.($slides + 2).'" '
            .'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" '
            .'Target="theme/theme1.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels.'</Relationships>';
    }

    private function slideMaster(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sldMaster '.self::NS.'><p:cSld>'
            .'<p:bg><p:bgPr><a:solidFill><a:schemeClr val="bg1"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            .'<p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .'</p:spTree></p:cSld>'
            .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" '
            .'accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" '
            .'hlink="hlink" folHlink="folHlink"/>'
            .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            .'<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles>'
            .'</p:sldMaster>';
    }

    private function slideMasterRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            .'</Relationships>';
    }

    private function slideLayout(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sldLayout '.self::NS.' type="blank" preserve="1"><p:cSld name="Blank"><p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            .'<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    }

    private function slideLayoutRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            .'</Relationships>';
    }

    private function slideRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'</Relationships>';
    }

    /**
     * The theme's accent slots carry the validated categorical palette, so a
     * reader that recolours by scheme lands on the same colours the charts
     * were checked against.
     */
    private function theme(): string
    {
        $palette = array_values((array) config('charts.categorical', []));
        $accent = fn (int $index, string $fallback) => ltrim($palette[$index] ?? $fallback, '#');

        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = '<a:ln w="9525" cap="flat" cmpd="sng" algn="ctr">'
            .'<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Atheris">'
            .'<a:themeElements>'
            .'<a:clrScheme name="Atheris">'
            .'<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>'
            .'<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            .'<a:dk2><a:srgbClr val="0B1F3A"/></a:dk2>'
            .'<a:lt2><a:srgbClr val="F7FAFC"/></a:lt2>'
            .'<a:accent1><a:srgbClr val="'.$accent(0, '2A78D6').'"/></a:accent1>'
            .'<a:accent2><a:srgbClr val="'.$accent(1, 'EB6834').'"/></a:accent2>'
            .'<a:accent3><a:srgbClr val="'.$accent(2, '1BAF7A').'"/></a:accent3>'
            .'<a:accent4><a:srgbClr val="'.$accent(3, 'EDA100').'"/></a:accent4>'
            .'<a:accent5><a:srgbClr val="'.$accent(4, 'E87BA4').'"/></a:accent5>'
            .'<a:accent6><a:srgbClr val="'.$accent(5, '008300').'"/></a:accent6>'
            .'<a:hlink><a:srgbClr val="0B1F3A"/></a:hlink>'
            .'<a:folHlink><a:srgbClr val="718096"/></a:folHlink>'
            .'</a:clrScheme>'
            .'<a:fontScheme name="Atheris">'
            .'<a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            .'<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            .'</a:fontScheme>'
            .'<a:fmtScheme name="Atheris">'
            .'<a:fillStyleLst>'.$fill.$fill.$fill.'</a:fillStyleLst>'
            .'<a:lnStyleLst>'.$line.$line.$line.'</a:lnStyleLst>'
            .'<a:effectStyleLst>'
            .'<a:effectStyle><a:effectLst/></a:effectStyle>'
            .'<a:effectStyle><a:effectLst/></a:effectStyle>'
            .'<a:effectStyle><a:effectLst/></a:effectStyle>'
            .'</a:effectStyleLst>'
            .'<a:bgFillStyleLst>'.$fill.$fill.$fill.'</a:bgFillStyleLst>'
            .'</a:fmtScheme>'
            .'</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }
}
