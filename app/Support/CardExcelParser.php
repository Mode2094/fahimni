<?php

namespace App\Support;

use ZipArchive;

class CardExcelParser
{
    public static function parse(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            return self::parseXlsx($path);
        }

        return self::parseCsv($path);
    }

    private static function parseXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open xlsx file.');
        }

        $shared = [];
        if (($s = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($s);
            foreach ($xml->si as $si) {
                $shared[] = (string) $si->t;
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheet === false) {
            throw new \RuntimeException('No worksheet found in xlsx file.');
        }

        $xml = simplexml_load_string($sheet);
        $codes = [];

        foreach ($xml->sheetData->row as $row) {
            $value = null;
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                if (preg_match('/^A\d+$/', $ref) !== 1) {
                    continue;
                }
                $type = (string) $cell['t'];
                if ($type === 's') {
                    $value = $shared[(int) $cell->v] ?? null;
                } elseif ($type === 'inlineStr') {
                    $value = (string) $cell->is->t;
                } else {
                    $value = (string) $cell->v;
                }
                break;
            }
            if ($value !== null && trim($value) !== '') {
                $codes[] = self::stripBom(trim((string) $value));
            }
        }

        return $codes;
    }

    private static function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read file.');
        }

        $raw = file_get_contents($path);
        $delimiter = self::detectDelimiter($raw);

        $codes = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $first = $row[0] ?? '';
            $first = self::stripBom(trim((string) $first));
            if ($first !== '') {
                $codes[] = $first;
            }
        }
        fclose($handle);

        return $codes;
    }

    private static function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    private static function detectDelimiter(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $counts = [
            ',' => substr_count($raw, ','),
            ';' => substr_count($raw, ';'),
            "\t" => substr_count($raw, "\t"),
        ];

        arsort($counts);

        return array_key_first($counts);
    }
}
