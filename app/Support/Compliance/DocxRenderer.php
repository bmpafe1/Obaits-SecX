<?php
namespace App\Support\Compliance;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocxRenderer
{
    private const NAVY  = '0B2545';
    private const SLATE = '5A6B82';
    private const HEAD_FILL = 'F0F3F8';
    private const BORDER = 'C9D2DE';

    public static function render(string $tenantId, array $doc, array $header, array $signatories, string $body): string
    {
        $w = new PhpWord();
        $w->addTitleStyle(1, ['bold' => true, 'size' => 14, 'color' => self::NAVY], ['spaceBefore' => 240, 'spaceAfter' => 80]);

        $s = $w->addSection(['marginTop' => 1000, 'marginBottom' => 1000, 'marginLeft' => 1100, 'marginRight' => 1100]);

        // ---- header / title block ----
        if (!empty($header['logo_path']) && Storage::disk('public')->exists($header['logo_path'])) {
            $s->addImage(Storage::disk('public')->path($header['logo_path']), ['height' => 46]);
        }
        $s->addText(self::clean($header['legal_name'] ?? ''), ['bold' => true, 'size' => 12, 'color' => self::SLATE]);
        $s->addText(self::clean($doc['title']), ['bold' => true, 'size' => 20, 'color' => self::NAVY], ['spaceAfter' => 40]);
        $addr = trim(implode(', ', array_filter([$header['address_line1'] ?? null, $header['city'] ?? null, $header['country'] ?? null])));
        if ($addr !== '') $s->addText(self::clean($addr), ['size' => 9, 'color' => self::SLATE]);
        if (!empty($header['registration_number'])) $s->addText('Company no. ' . self::clean($header['registration_number']), ['size' => 9, 'color' => self::SLATE]);
        $s->addTextBreak(1);

        // ---- BODY: parse the Markdown into real Word objects ----
        self::renderMarkdown($s, $body);

        // ---- document control + signatures ----
        $s->addTextBreak(1);
        $s->addTitle('Document control', 1);
        $tr = $s->addTextRun(['spaceAfter' => 120]);
        $tr->addText('Version ' . ($header['doc_version'] ?? '1.0') . '   ·   ', ['size' => 9, 'color' => self::SLATE]);
        $tr->addText('Effective ' . ($header['effective_date'] ?? '[to be set on approval]') . '   ·   ', ['size' => 9, 'color' => self::SLATE]);
        $tr->addText('Review cycle: ' . ($header['review_cycle'] ?? 'annual'), ['size' => 9, 'color' => self::SLATE]);

        $labels = ['prepared_by' => 'Prepared by', 'reviewed_by' => 'Reviewed by', 'approved_by' => 'Approved by'];
        $sigRows = [];
        foreach ($labels as $key => $label) {
            foreach (($signatories[$key] ?? []) as $p) {
                $sigRows[] = [$label, ($p['name'] ?? '') . ' — ' . ($p['title'] ?? ''), '________________', '__________'];
            }
        }
        if ($sigRows) self::table($s, ['Role', 'Name', 'Signature', 'Date'], $sigRows);

        $dir  = "documents/{$tenantId}";
        $file = $dir . '/' . Str::slug($doc['title']) . '-v' . ($header['doc_version'] ?? '1') . '-' . time() . '.docx';
        Storage::disk('public')->makeDirectory($dir);
        IOFactory::createWriter($w, 'Word2007')->save(Storage::disk('public')->path($file));

        return $file;
    }

    // ---- the Markdown parser: handles ## , **bold**, - bullets, and | tables | ----
    private static function renderMarkdown($s, string $body): void
    {
        $lines = explode("\n", $body);
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = rtrim($lines[$i]);

            // blank line
            if ($line === '') { $s->addTextBreak(1); $i++; continue; }

            // heading: ## Text
            // sub-heading: ### Text  (must come BEFORE the ## check)
            if (str_starts_with($line, '### ')) {
                $s->addText(self::clean(substr($line, 4)), ['bold' => true, 'size' => 11, 'color' => self::NAVY], ['spaceBefore' => 120, 'spaceAfter' => 40]);
                $i++; continue;
            }

            // heading: ## Text
            if (str_starts_with($line, '## ')) {
                $s->addTitle(self::clean(substr($line, 3)), 1);
                $i++; continue;
            }

            // table: a line starting with | , followed by a |---| separator
            if (str_starts_with($line, '|') && isset($lines[$i + 1]) && preg_match('/^\|[\s\-:|]+\|/', trim($lines[$i + 1]))) {
                $headers = self::splitRow($line);
                $rows = [];
                $i += 2; // skip header and separator
                while ($i < $n && str_starts_with(rtrim($lines[$i]), '|')) {
                    $rows[] = self::splitRow(rtrim($lines[$i]));
                    $i++;
                }
                self::table($s, $headers, $rows);
                continue;
            }

            // bullet: - item
            // bullet: - item
            if (str_starts_with($line, '- ')) {
                while ($i < $n && str_starts_with(rtrim($lines[$i]), '- ')) {
                    $text = substr(rtrim($lines[$i]), 2);
                    $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text);   // plain bold in bullets
                    $s->addListItem(self::clean($text), 0, ['size' => 11]);
                    $i++;
                }
                continue;
            }

            // plain paragraph (may contain **bold**)
            $para = $s->addTextRun(['spaceAfter' => 100]);
            self::inline($para, $line);
            $i++;
        }
    }

    // render a line into a text run, converting **bold** to bold runs
    // render a line into a text run, converting **bold** and *italic* to styled runs
    private static function inline($run, string $text): void
    {
        if ($run === null) return;
        $parts = preg_split('/(\*\*.*?\*\*|\*.*?\*)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (str_starts_with($part, '**') && str_ends_with($part, '**')) {
                $run->addText(self::clean(substr($part, 2, -2)), ['bold' => true, 'size' => 11]);
            } elseif (str_starts_with($part, '*') && str_ends_with($part, '*') && strlen($part) > 2) {
                $run->addText(self::clean(substr($part, 1, -1)), ['italic' => true, 'size' => 10, 'color' => self::SLATE]);
            } else {
                $run->addText(self::clean($part), ['size' => 11]);
            }
        }
    }

    private static function splitRow(string $line): array
    {
        $line = trim($line, "| \t");
        return array_map('trim', explode('|', $line));
    }

    private static function table($s, array $headers, array $rows): void
    {
        $t = $s->addTable(['borderColor' => self::BORDER, 'borderSize' => 6, 'cellMargin' => 70, 'width' => 100 * 50, 'unit' => 'pct']);
        if ($headers) {
            $t->addRow(null, ['tblHeader' => true]);
            foreach ($headers as $h) {
                $t->addCell(null, ['bgColor' => self::HEAD_FILL])->addText(self::clean($h), ['bold' => true, 'size' => 9, 'color' => self::NAVY]);
            }
        }
        foreach ($rows as $row) {
            $t->addRow();
            foreach ($row as $val) {
                // strip **bold** markers inside cells (render plain in tables)
                $val = preg_replace('/\*\*(.*?)\*\*/', '$1', (string) $val);
                $t->addCell()->addText(self::clean($val), ['size' => 9]);
            }
        }
        $s->addTextBreak(1);
    }

    private static function clean(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}