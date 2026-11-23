<?php
/**
 * Generator Ekspor Laporan Penjualan Barang ke Format Native XLSX (Excel) dan PDF 1.4
 * Menggunakan ekstensi standar PHP (zlib + gd), tanpa ketergantungan library/internet eksternal.
 * 
 * Menampilkan:
 * - Logo
 * - Nama Yayasan / Nama Aplikasi
 * - Tanggal Laporan
 * - Pelapor
 * - Tabel Data: Tanggal Transaksi, Kode Nota, Nama Barang yang Terjual, Jumlah, Harga Jual, Total Harga Jual
 * - Bagian Bawah: Total Barang yang Terjual, Total Harga Jual
 */

class ReportZipBuilder {
    private $entries = [];

    public function addFile($name, $data) {
        $this->entries[] = [
            'name' => str_replace('\\', '/', $name),
            'data' => (string)$data,
        ];
    }

    public function build() {
        $fileData = '';
        $centralDir = '';
        $offset = 0;

        // DOS timestamp standar
        $dosTime = 0x0000;
        $dosDate = 0x5421;

        foreach ($this->entries as $entry) {
            $name = $entry['name'];
            $raw = $entry['data'];
            $uncSize = strlen($raw);
            $crc = crc32($raw);
            if (function_exists('gzdeflate')) {
                $comp = gzdeflate($raw, 6);
                $method = 8;
            } else {
                $comp = $raw;
                $method = 0;
            }
            $compSize = strlen($comp);
            $nameLen = strlen($name);

            // Local File Header (flag bit 11 = 0x0800 untuk UTF-8)
            $localHdr = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0x0800,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $compSize,
                $uncSize,
                $nameLen,
                0
            );
            $fileData .= $localHdr . $name . $comp;

            // Central Directory Header
            $cdHdr = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0x0800,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $compSize,
                $uncSize,
                $nameLen,
                0,
                0,
                0,
                0,
                32,
                $offset
            );
            $centralDir .= $cdHdr . $name;

            $offset += strlen($localHdr) + $nameLen + $compSize;
        }

        $count = count($this->entries);
        $cdSize = strlen($centralDir);
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            $cdSize,
            $offset,
            0
        );

        return $fileData . $centralDir . $eocd;
    }
}

class SimpleXlsxReport {
    private static function xmlEsc($str) {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$str);
        return htmlspecialchars($clean ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function colLetter($idx1Based) {
        $letter = '';
        $n = (int)$idx1Based;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $n = intdiv($n - 1, 26);
        }
        return $letter;
    }

    private static function cellStr($col, $row, $val, $styleIdx = 0) {
        $ref = self::colLetter($col) . $row;
        $esc = self::xmlEsc($val);
        return '<c r="' . $ref . '" t="inlineStr" s="' . (int)$styleIdx . '"><is><t xml:space="preserve">' . $esc . '</t></is></c>';
    }

    private static function cellNum($col, $row, $num, $styleIdx = 0) {
        $ref = self::colLetter($col) . $row;
        $val = is_numeric($num) ? (string)(0 + $num) : '0';
        return '<c r="' . $ref . '" s="' . (int)$styleIdx . '"><v>' . $val . '</v></c>';
    }

    private static function cellFormula($col, $row, $formula, $cachedVal, $styleIdx = 0) {
        $ref = self::colLetter($col) . $row;
        $val = is_numeric($cachedVal) ? (string)(0 + $cachedVal) : '0';
        return '<c r="' . $ref . '" s="' . (int)$styleIdx . '"><f>' . self::xmlEsc($formula) . '</f><v>' . $val . '</v></c>';
    }

    private static function prepareLogoPng($logoAbsPath) {
        if (empty($logoAbsPath) || !file_exists($logoAbsPath) || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $raw = @file_get_contents($logoAbsPath);
        if (!$raw) return null;
        $src = @imagecreatefromstring($raw);
        if (!$src) return null;

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0) {
            imagedestroy($src);
            return null;
        }

        $maxSide = 160;
        $scale = min($maxSide / $sw, $maxSide / $sh, 1.0);
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));

        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $trans);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);

        ob_start();
        imagepng($dst);
        $pngBytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $pngBytes ? ['bytes' => $pngBytes, 'w' => $dw, 'h' => $dh] : null;
    }

    public static function generate(array $rows, array $meta, $orientation = 'portrait') {
        $orient      = ($orientation === 'landscape') ? 'landscape' : 'portrait';
        $appName     = $meta['appName'] ?? 'QiOS Cendekia';
        $yayasanName = trim($meta['yayasanName'] ?? '');
        $subtitle    = $meta['subtitle'] ?? 'Laporan Penjualan Barang';
        $reportDate  = $meta['reportDate'] ?? date('d/m/Y');
        $adminName   = $meta['adminName'] ?? 'Administrator';
        $adminRole   = $meta['adminRole'] ?? 'Administrator';
        $logoInfo    = self::prepareLogoPng($meta['logoAbsPath'] ?? '');

        $zip = new ReportZipBuilder();

        // 1. [Content_Types].xml
        $pngContentType = $logoInfo ? '<Default Extension="png" ContentType="image/png"/>' : '';
        $drawingOverride = $logoInfo ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . $pngContentType
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $drawingOverride
            . '</Types>';
        $zip->addFile('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $zip->addFile('_rels/.rels', $rootRels);

        // 3. xl/workbook.xml & xl/_rels/workbook.xml.rels
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Laporan Penjualan" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
        $zip->addFile('xl/workbook.xml', $workbook);

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
        $zip->addFile('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/styles.xml
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp &quot;#,##0"/></numFmts>'
            . '<fonts count="7">'
            .   '<font><sz val="10"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="16"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
            .   '<font><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="10.5"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .   '<font><sz val="10.5"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .   '<font><i/><sz val="9.5"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEDEDED"/></patternFill></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF7F7F7"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
            .   '<border>'
            .     '<left style="thin"><color rgb="FF000000"/></left>'
            .     '<right style="thin"><color rgb="FF000000"/></right>'
            .     '<top style="thin"><color rgb="FF000000"/></top>'
            .     '<bottom style="thin"><color rgb="FF000000"/></bottom>'
            .     '<diagonal/>'
            .   '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .   '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="6" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';
        $zip->addFile('xl/styles.xml', $styles);

        // 5. xl/worksheets/sheet1.xml
        $sheetRows = [];
        $merges = [];

        // Header aplikasi, yayasan, logo, tanggal laporan & pelapor
        $titleStartCol = $logoInfo ? 2 : 1;
        $titleText = !empty($yayasanName) ? ($yayasanName . ' - ' . $appName) : $appName;
        $metaLine  = 'Tanggal Laporan: ' . $reportDate . '  |  Pelapor: ' . $adminName . ' (' . $adminRole . ')';

        $merges[] = self::colLetter($titleStartCol) . '1:G1';
        $merges[] = self::colLetter($titleStartCol) . '2:G2';
        $merges[] = self::colLetter($titleStartCol) . '3:G3';

        $sheetRows[] = '<row r="1" ht="26" customHeight="1">' . self::cellStr($titleStartCol, 1, $titleText, 1) . '</row>';
        $sheetRows[] = '<row r="2" ht="19" customHeight="1">' . self::cellStr($titleStartCol, 2, 'Laporan Penjualan Barang (' . $subtitle . ')', 2) . '</row>';
        $sheetRows[] = '<row r="3" ht="18" customHeight="1">' . self::cellStr($titleStartCol, 3, $metaLine, 12) . '</row>';
        $sheetRows[] = '<row r="4" ht="10" customHeight="1"/>';

        // Header tabel pada row 5 (7 Kolom: No, Tanggal Transaksi, Kode Nota, Nama Barang yang Terjual, Jumlah, Harga Jual, Total Harga Jual)
        $headers = ['No', 'Tanggal Transaksi', 'Kode Nota', 'Nama Barang yang Terjual', 'Jumlah', 'Harga Jual', 'Total Harga Jual'];
        $hdrCells = '';
        foreach ($headers as $idx => $hText) {
            $hdrCells .= self::cellStr($idx + 1, 5, $hText, 3);
        }
        $sheetRows[] = '<row r="5" ht="24" customHeight="1">' . $hdrCells . '</row>';

        $rowNum = 6;
        $firstDataRow = $rowNum;
        $sumQty = 0;
        $sumSubtotal = 0.0;

        if (empty($rows)) {
            $emptyCells = self::cellStr(1, $rowNum, 'Tidak ada data transaksi pada daftar.', 4);
            for ($c = 2; $c <= 7; $c++) {
                $emptyCells .= self::cellStr($c, $rowNum, '', 4);
            }
            $sheetRows[] = '<row r="' . $rowNum . '" ht="24" customHeight="1">' . $emptyCells . '</row>';
            $merges[] = 'A' . $rowNum . ':G' . $rowNum;
            $rowNum++;
        } else {
            $no = 1;
            foreach ($rows as $r) {
                $qty      = (int)($r['jumlah'] ?? 0);
                $hrgJual  = (float)($r['harga_jual'] ?? 0);
                $subtotal = (float)($r['total_harga_jual'] ?? 0);
                $sumQty      += $qty;
                $sumSubtotal += $subtotal;

                $cells = '';
                $cells .= self::cellNum(1, $rowNum, $no++, 4);
                $cells .= self::cellStr(2, $rowNum, $r['tanggal_transaksi'] ?? '', 4);
                $cells .= self::cellStr(3, $rowNum, $r['kode_nota'] ?? '', 4);
                $cells .= self::cellStr(4, $rowNum, $r['nama_barang'] ?? '', 5);
                $cells .= self::cellNum(5, $rowNum, $qty, 4);
                $cells .= self::cellNum(6, $rowNum, $hrgJual, 6);
                $cells .= self::cellNum(7, $rowNum, $subtotal, 6);

                $sheetRows[] = '<row r="' . $rowNum . '" ht="20" customHeight="1">' . $cells . '</row>';
                $rowNum++;
            }
            $lastDataRow = $rowNum - 1;

            // Baris Total Penjualan (Bagian Bawah: Total Barang Terjual & Total Harga Jual)
            $totalLabel = 'TOTAL KESELURUHAN';
            $totCells = self::cellStr(1, $rowNum, $totalLabel, 7);
            for ($c = 2; $c <= 4; $c++) {
                $totCells .= self::cellStr($c, $rowNum, '', 7);
            }
            // Col 5: Total Barang yang Terjual
            $totCells .= self::cellFormula(5, $rowNum, 'SUM(E' . $firstDataRow . ':E' . $lastDataRow . ')', $sumQty, 11);
            // Col 6: -
            $totCells .= self::cellStr(6, $rowNum, '-', 7);
            // Col 7: Total Harga Jual
            $totCells .= self::cellFormula(7, $rowNum, 'SUM(G' . $firstDataRow . ':G' . $lastDataRow . ')', $sumSubtotal, 8);

            $sheetRows[] = '<row r="' . $rowNum . '" ht="22" customHeight="1">' . $totCells . '</row>';
            $merges[] = 'A' . $rowNum . ':D' . $rowNum;
            $rowNum++;
        }

        // Blok Tanda Tangan Pelapor di kanan bawah
        $sigRow = $rowNum + 3;
        $sheetRows[] = '<row r="' . $sigRow . '" ht="18" customHeight="1">' . self::cellStr(6, $sigRow, $reportDate, 10) . '</row>';
        $sheetRows[] = '<row r="' . ($sigRow + 1) . '" ht="18" customHeight="1">' . self::cellStr(6, $sigRow + 1, 'Pelapor / Penanggung Jawab,', 10) . '</row>';
        $sheetRows[] = '<row r="' . ($sigRow + 4) . '" ht="20" customHeight="1">' . self::cellStr(6, $sigRow + 4, $adminName, 9) . '</row>';
        $sheetRows[] = '<row r="' . ($sigRow + 5) . '" ht="18" customHeight="1">' . self::cellStr(6, $sigRow + 5, $adminRole, 10) . '</row>';
        $merges[] = 'F' . $sigRow . ':G' . $sigRow;
        $merges[] = 'F' . ($sigRow + 1) . ':G' . ($sigRow + 1);
        $merges[] = 'F' . ($sigRow + 4) . ':G' . ($sigRow + 4);
        $merges[] = 'F' . ($sigRow + 5) . ':G' . ($sigRow + 5);

        $mergeXml = '';
        if (!empty($merges)) {
            $mergeXml = '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) {
                $mergeXml .= '<mergeCell ref="' . $m . '"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        $drawingTag = $logoInfo ? '<drawing r:id="rId1"/>' : '';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>'
            .   '<col min="1" max="1" width="8" customWidth="1"/>'
            .   '<col min="2" max="2" width="20" customWidth="1"/>'
            .   '<col min="3" max="3" width="22" customWidth="1"/>'
            .   '<col min="4" max="4" width="38" customWidth="1"/>'
            .   '<col min="5" max="5" width="16" customWidth="1"/>'
            .   '<col min="6" max="6" width="18" customWidth="1"/>'
            .   '<col min="7" max="7" width="22" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . $mergeXml
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="' . $orient . '" fitToWidth="1" fitToHeight="0"/>'
            . $drawingTag
            . '</worksheet>';

        $zip->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        // 6. Logo Drawing (jika logo tersedia)
        if ($logoInfo) {
            $zip->addFile('xl/media/logo.png', $logoInfo['bytes']);

            $sheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
                . '</Relationships>';
            $zip->addFile('xl/worksheets/_rels/sheet1.xml.rels', $sheetRels);

            $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/>'
                . '</Relationships>';
            $zip->addFile('xl/drawings/_rels/drawing1.xml.rels', $drawingRels);

            $maxPx = 56;
            $ratio = min($maxPx / $logoInfo['w'], $maxPx / $logoInfo['h']);
            $cx = (int)round($logoInfo['w'] * $ratio * 9525);
            $cy = (int)round($logoInfo['h'] * $ratio * 9525);

            $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<xdr:oneCellAnchor>'
                . '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>25400</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>25400</xdr:rowOff></xdr:from>'
                . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<xdr:pic>'
                . '<xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr/></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                . '</xdr:pic>'
                . '<xdr:clientData/>'
                . '</xdr:oneCellAnchor>'
                . '</xdr:wsDr>';
            $zip->addFile('xl/drawings/drawing1.xml', $drawingXml);
        }

        return $zip->build();
    }
}

class SimplePdfReport {
    private static $k = 72.0 / 25.4; // konversi mm ke pt (~2.83464567)

    private static $helvWidths = [
        32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,
        40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,
        56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,
        64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,
        80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,
        88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,
        96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,
        104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,
        112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,
        120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
    ];

    private static $helvBoldWidths = [
        32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,
        40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,
        56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,62=>584,63=>611,
        64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,
        72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,
        80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,
        88=>667,89=>667,90=>611,91=>333,92=>278,93=>333,94=>584,95=>556,
        96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,102=>333,103=>611,
        104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,111=>611,
        112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,
        120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584,
    ];

    public static function formatIndoDate($dateStr = null) {
        $timestamp = $dateStr ? strtotime($dateStr) : time();
        $bulanIndo = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        $d = date('j', $timestamp);
        $m = (int)date('n', $timestamp);
        $y = date('Y', $timestamp);
        return $d . ' ' . ($bulanIndo[$m] ?? date('F', $timestamp)) . ' ' . $y;
    }

    private static function toWin1252($str) {
        $s = (string)$str;
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
            if ($conv !== false) return $conv;
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $s);
    }

    private static function pdfEsc($win1252Str) {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $win1252Str);
    }

    private static function measureMm($win1252Str, $fontRef, $fontSizePt) {
        $len = strlen($win1252Str);
        $totalUnits = 0;
        if ($fontRef === 'F3') {
            $totalUnits = $len * 600;
        } else {
            $map = ($fontRef === 'F2') ? self::$helvBoldWidths : self::$helvWidths;
            for ($i = 0; $i < $len; $i++) {
                $ord = ord($win1252Str[$i]);
                $totalUnits += $map[$ord] ?? 556;
            }
        }
        $ptWidth = ($totalUnits / 1000.0) * $fontSizePt;
        return $ptWidth / self::$k;
    }

    private static function wrapLines($utf8Str, $fontRef, $fontSizePt, $maxWidthMm) {
        $s = trim(str_replace(["\r", "\n"], ' ', self::toWin1252($utf8Str)));
        if ($s === '') return [''];

        $words = preg_split('/\s+/', $s);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if (self::measureMm($word, $fontRef, $fontSizePt) > $maxWidthMm) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $part = '';
                $wlen = strlen($word);
                for ($i = 0; $i < $wlen; $i++) {
                    $ch = $word[$i];
                    if ($part !== '' && self::measureMm($part . $ch, $fontRef, $fontSizePt) > $maxWidthMm) {
                        $lines[] = $part;
                        $part = $ch;
                    } else {
                        $part .= $ch;
                    }
                }
                $current = $part;
                continue;
            }

            $candidate = ($current === '') ? $word : ($current . ' ' . $word);
            if (self::measureMm($candidate, $fontRef, $fontSizePt) <= $maxWidthMm) {
                $current = $candidate;
            } else {
                if ($current !== '') $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return empty($lines) ? [''] : $lines;
    }

    private static function prepareLogoRgb($logoAbsPath) {
        if (empty($logoAbsPath) || !file_exists($logoAbsPath) || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $raw = @file_get_contents($logoAbsPath);
        if (!$raw) return null;
        $src = @imagecreatefromstring($raw);
        if (!$src) return null;

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0) {
            imagedestroy($src);
            return null;
        }

        $maxSide = 220;
        $scale = min($maxSide / $sw, $maxSide / $sh, 1.0);
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));

        $canvas = imagecreatetruecolor($dw, $dh);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dw, $dh, $white);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);

        $rgb = '';
        for ($y = 0; $y < $dh; $y++) {
            for ($x = 0; $x < $dw; $x++) {
                $px = imagecolorat($canvas, $x, $y);
                $r = ($px >> 16) & 0xFF;
                $g = ($px >> 8) & 0xFF;
                $b = $px & 0xFF;
                $rgb .= chr($r) . chr($g) . chr($b);
            }
        }

        imagedestroy($src);
        imagedestroy($canvas);

        $compressed = function_exists('gzcompress') ? gzcompress($rgb, 6) : $rgb;
        return [
            'data'       => $compressed,
            'compressed' => function_exists('gzcompress'),
            'w'          => $dw,
            'h'          => $dh,
        ];
    }

    private static function formatRupiah($amount) {
        return 'Rp ' . number_format((float)$amount, 0, ',', '.');
    }

    public static function generate(array $rows, array $meta, $orientation = 'portrait') {
        $isLandscape = ($orientation === 'landscape');
        $pageW = $isLandscape ? 297.0 : 210.0;
        $pageH = $isLandscape ? 210.0 : 297.0;
        $marginX = 12.0;
        $marginTop = 12.0;
        $marginBottom = 12.0;
        $usableW = $pageW - ($marginX * 2); // Portrait: 186 mm, Landscape: 273 mm

        $appName     = $meta['appName'] ?? 'QiOS Cendekia';
        $yayasanName = trim($meta['yayasanName'] ?? '');
        $subtitle    = $meta['subtitle'] ?? 'Laporan Penjualan Barang';
        $reportDate  = $meta['reportDate'] ?? date('d/m/Y');
        $adminName   = $meta['adminName'] ?? 'Administrator';
        $adminRole   = $meta['adminRole'] ?? 'Administrator';
        $logoRgb     = self::prepareLogoRgb($meta['logoAbsPath'] ?? '');

        $k = self::$k;
        $pageWpt = $pageW * $k;
        $pageHpt = $pageH * $k;
        $yPt = function ($yMm) use ($pageH, $k) {
            return ($pageH - $yMm) * $k;
        };
        $xPt = function ($xMm) use ($k) {
            return $xMm * $k;
        };

        // Konfigurasi 7 Kolom presisi:
        // No, Tanggal Transaksi, Kode Nota, Nama Barang yang Terjual, Jumlah, Harga Jual, Total Harga Jual
        // Portrait total: 10 + 28 + 30 + 56 + 16 + 23 + 23 = 186 mm
        // Landscape total: 12 + 38 + 42 + 95 + 22 + 32 + 32 = 273 mm
        $colWidths = $isLandscape
            ? [12.0, 38.0, 42.0, 95.0, 22.0, 32.0, 32.0]
            : [10.0, 28.0, 30.0, 56.0, 16.0, 23.0, 23.0];

        $headers = ['No', 'Tanggal Transaksi', 'Kode Nota', 'Nama Barang yang Terjual', 'Jumlah', 'Harga Jual', 'Total Harga Jual'];
        $bodyFontSize = $isLandscape ? 9.2 : 8.2;
        $monoFontSize = $isLandscape ? 8.8 : 7.6;
        $hdrFontSize  = $isLandscape ? 9.2 : 8.2;
        $cellPadX     = $isLandscape ? 2.0 : 1.2;
        $cellPadY     = 1.8;
        $lineHeight   = $isLandscape ? 4.2 : 3.8;

        $pages = [];
        $curStream = '';
        $curY = $marginTop;

        $newPage = function () use (&$pages, &$curStream, &$curY, $marginTop) {
            if ($curStream !== '') {
                $pages[] = $curStream;
            }
            $curStream = "0.28 w\n";
            $curY = $marginTop;
        };

        $drawText = function ($xMm, $baselineMm, $winText, $fontRef, $sizePt, $rgb = '0 0 0') use (&$curStream, $xPt, $yPt) {
            $curStream .= sprintf(
                "BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n",
                $fontRef,
                $sizePt,
                $rgb,
                $xPt($xMm),
                $yPt($baselineMm),
                self::pdfEsc($winText)
            );
        };

        $drawRect = function ($xMm, $topMm, $wMm, $hMm, $fillRgb = null, $stroke = true) use (&$curStream, $xPt, $yPt, $k) {
            $bottomMm = $topMm + $hMm;
            $x = $xPt($xMm);
            $y = $yPt($bottomMm);
            $w = $wMm * $k;
            $h = $hMm * $k;
            if ($fillRgb !== null) {
                $curStream .= sprintf("%s rg 0 0 0 RG %.2F %.2F %.2F %.2F re %s\n", $fillRgb, $x, $y, $w, $h, $stroke ? 'B' : 'f');
            } elseif ($stroke) {
                $curStream .= sprintf("0 0 0 RG %.2F %.2F %.2F %.2F re S\n", $x, $y, $w, $h);
            }
        };

        $drawHeaderBanner = function () use (&$curY, &$curStream, $marginX, $logoRgb, $appName, $yayasanName, $subtitle, $reportDate, $adminName, $adminRole, $xPt, $yPt, $k, $drawText) {
            $headerH = 17.0;
            $textStartX = $marginX;

            if ($logoRgb !== null) {
                $boxMm = 16.0;
                $ratio = min($boxMm / $logoRgb['w'], $boxMm / $logoRgb['h']);
                $imgWmm = $logoRgb['w'] * $ratio;
                $imgHmm = $logoRgb['h'] * $ratio;
                $imgX = $marginX + (($boxMm - $imgWmm) / 2.0);
                $imgYBottom = $curY + (($boxMm - $imgHmm) / 2.0) + $imgHmm;

                $curStream .= sprintf(
                    "q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n",
                    $imgWmm * $k,
                    $imgHmm * $k,
                    $xPt($imgX),
                    $yPt($imgYBottom)
                );
                $textStartX = $marginX + $boxMm + 4.0;
            }

            if (!empty($yayasanName)) {
                $drawText($textStartX, $curY + 5.0, self::toWin1252($yayasanName), 'F2', 13.5, '0 0 0');
                $drawText($textStartX, $curY + 9.5, self::toWin1252($appName . ' - Laporan Penjualan Barang'), 'F2', 10.5, '0.15 0.15 0.15');
                $metaStr = 'Tanggal Laporan: ' . $reportDate . '  |  Pelapor: ' . $adminName . ' (' . $adminRole . ')' . (!empty($subtitle) ? '  |  ' . $subtitle : '');
                $drawText($textStartX, $curY + 14.0, self::toWin1252($metaStr), 'F1', 8.5, '0.35 0.35 0.35');
            } else {
                $drawText($textStartX, $curY + 6.0, self::toWin1252($appName . ' - Laporan Penjualan Barang'), 'F2', 14.5, '0 0 0');
                $metaStr = 'Tanggal Laporan: ' . $reportDate . '  |  Pelapor: ' . $adminName . ' (' . $adminRole . ')' . (!empty($subtitle) ? '  |  ' . $subtitle : '');
                $drawText($textStartX, $curY + 12.0, self::toWin1252($metaStr), 'F1', 8.8, '0.35 0.35 0.35');
            }

            $curY += $headerH + 3.0;
        };

        $drawTableHeader = function () use (&$curY, $marginX, $colWidths, $headers, $hdrFontSize, $cellPadY, $drawRect, $drawText) {
            $hdrH = 8.0;
            $x = $marginX;
            foreach ($headers as $i => $hText) {
                $w = $colWidths[$i];
                $drawRect($x, $curY, $w, $hdrH, '0.93 0.93 0.93', true);
                $win = self::toWin1252($hText);
                $tw = self::measureMm($win, 'F2', $hdrFontSize);
                $tx = $x + max(0.5, ($w - $tw) / 2.0);
                $ty = $curY + ($hdrH / 2.0) + ($hdrFontSize * 0.13);
                $drawText($tx, $ty, $win, 'F2', $hdrFontSize, '0 0 0');
                $x += $w;
            }
            $curY += $hdrH;
        };

        $newPage();
        $drawHeaderBanner();
        $drawTableHeader();

        $totalQty = 0;
        $totalSubtotal = 0.0;

        if (empty($rows)) {
            $emptyH = 10.0;
            $drawRect($marginX, $curY, $usableW, $emptyH, null, true);
            $msg = self::toWin1252('Tidak ada data penjualan barang pada daftar.');
            $mw = self::measureMm($msg, 'F1', $bodyFontSize);
            $drawText($marginX + ($usableW - $mw) / 2.0, $curY + 6.0, $msg, 'F1', $bodyFontSize, '0.35 0.35 0.35');
            $curY += $emptyH;
        } else {
            $no = 1;
            foreach ($rows as $row) {
                $vQty = (int)($row['jumlah'] ?? 0);
                $vHrg = (float)($row['harga_jual'] ?? 0);
                $vSub = (float)($row['total_harga_jual'] ?? 0);
                $totalQty += $vQty;
                $totalSubtotal += $vSub;

                $cellsSpec = [
                    ['text' => (string)$no++, 'font' => 'F1', 'size' => $bodyFontSize, 'align' => 'C'],
                    ['text' => (string)($row['tanggal_transaksi'] ?? ''), 'font' => 'F1', 'size' => $bodyFontSize, 'align' => 'C'],
                    ['text' => (string)($row['kode_nota'] ?? ''), 'font' => 'F3', 'size' => $monoFontSize, 'align' => 'C'],
                    ['text' => (string)($row['nama_barang'] ?? ''), 'font' => 'F1', 'size' => $bodyFontSize, 'align' => 'L'],
                    ['text' => (string)$vQty . ' ' . ($row['satuan'] ?? 'pcs'), 'font' => 'F1', 'size' => $bodyFontSize, 'align' => 'C'],
                    ['text' => self::formatRupiah($vHrg), 'font' => 'F3', 'size' => $monoFontSize, 'align' => 'R'],
                    ['text' => self::formatRupiah($vSub), 'font' => 'F3', 'size' => $monoFontSize, 'align' => 'R'],
                ];

                $wrapped = [];
                $maxLines = 1;
                foreach ($cellsSpec as $i => $spec) {
                    $avail = max(3.0, $colWidths[$i] - ($cellPadX * 2));
                    $lines = self::wrapLines($spec['text'], $spec['font'], $spec['size'], $avail);
                    $wrapped[$i] = $lines;
                    if (count($lines) > $maxLines) $maxLines = count($lines);
                }

                $rowH = max(6.8, ($maxLines * $lineHeight) + ($cellPadY * 2));

                if ($curY + $rowH > $pageH - $marginBottom) {
                    $newPage();
                    $drawTableHeader();
                }

                $x = $marginX;
                foreach ($cellsSpec as $i => $spec) {
                    $w = $colWidths[$i];
                    $drawRect($x, $curY, $w, $rowH, null, true);

                    $lines = $wrapped[$i];
                    $contentH = count($lines) * $lineHeight;
                    $startY = $curY + (($rowH - $contentH) / 2.0) + ($lineHeight * 0.5) + ($spec['size'] * 0.127);

                    foreach ($lines as $lIdx => $lineWin) {
                        $tw = self::measureMm($lineWin, $spec['font'], $spec['size']);
                        if ($spec['align'] === 'R') {
                            $tx = $x + $w - $cellPadX - $tw;
                        } elseif ($spec['align'] === 'C') {
                            $tx = $x + (($w - $tw) / 2.0);
                        } else {
                            $tx = $x + $cellPadX;
                        }
                        $drawText($tx, $startY + ($lIdx * $lineHeight), $lineWin, $spec['font'], $spec['size'], '0 0 0');
                    }
                    $x += $w;
                }
                $curY += $rowH;
            }

            // Baris Total Penjualan (Bagian Bawah: Total Barang yang Terjual & Total Harga Jual)
            $totH = 8.0;
            if ($curY + $totH > $pageH - $marginBottom) {
                $newPage();
                $drawTableHeader();
            }

            $leftTotalW = $colWidths[0] + $colWidths[1] + $colWidths[2] + $colWidths[3];
            $drawRect($marginX, $curY, $leftTotalW, $totH, '0.97 0.97 0.97', true);
            $totLabel = self::toWin1252('TOTAL KESELURUHAN');
            $tlw = self::measureMm($totLabel, 'F2', $bodyFontSize);
            $drawText($marginX + $leftTotalW - $cellPadX - $tlw, $curY + ($totH / 2.0) + ($bodyFontSize * 0.13), $totLabel, 'F2', $bodyFontSize, '0 0 0');

            // Col 4: Total Barang yang Terjual
            $wQty = $colWidths[4];
            $drawRect($marginX + $leftTotalW, $curY, $wQty, $totH, '0.97 0.97 0.97', true);
            $qtyStr = self::toWin1252($totalQty . ' pcs');
            $qw = self::measureMm($qtyStr, 'F2', $bodyFontSize);
            $drawText($marginX + $leftTotalW + (($wQty - $qw) / 2.0), $curY + ($totH / 2.0) + ($bodyFontSize * 0.13), $qtyStr, 'F2', $bodyFontSize, '0 0 0');

            // Col 5: Strip '-'
            $wHrg = $colWidths[5];
            $xHrg = $marginX + $leftTotalW + $wQty;
            $drawRect($xHrg, $curY, $wHrg, $totH, '0.97 0.97 0.97', true);
            $stripStr = self::toWin1252('-');
            $sw = self::measureMm($stripStr, 'F2', $bodyFontSize);
            $drawText($xHrg + (($wHrg - $sw) / 2.0), $curY + ($totH / 2.0) + ($bodyFontSize * 0.13), $stripStr, 'F2', $bodyFontSize, '0 0 0');

            // Col 6: Total Harga Jual
            $wSub = $colWidths[6];
            $xSub = $xHrg + $wHrg;
            $drawRect($xSub, $curY, $wSub, $totH, '0.97 0.97 0.97', true);
            $totStr = self::toWin1252(self::formatRupiah($totalSubtotal));
            $tw = self::measureMm($totStr, 'F2', $bodyFontSize);
            $drawText($xSub + $wSub - $cellPadX - $tw, $curY + ($totH / 2.0) + ($bodyFontSize * 0.13), $totStr, 'F2', $bodyFontSize, '0 0 0');

            $curY += $totH;
        }

        // Blok Tanda Tangan Pelapor di pojok kanan bawah
        $sigNeedH = 26.0;
        if ($curY + $sigNeedH > $pageH - $marginBottom) {
            $newPage();
        }
        $curY += 8.0;
        $sigW = 60.0;
        $sigX = $marginX + $usableW - $sigW;
        $drawText($sigX, $curY, self::toWin1252($reportDate), 'F1', 8.5, '0 0 0');
        $drawText($sigX, $curY + 4.2, self::toWin1252('Pelapor / Penanggung Jawab,'), 'F1', 8.5, '0 0 0');
        $curY += 15.0; // ruang tanda tangan
        $drawText($sigX, $curY, self::toWin1252($adminName), 'F2', 9.2, '0 0 0');
        $drawText($sigX, $curY + 4.2, self::toWin1252($adminRole), 'F1', 8.5, '0.3 0.3 0.3');

        if ($curStream !== '') {
            $pages[] = $curStream;
        }

        // Susun objek PDF 1.4 standar
        $objects = [];
        $nextObj = 1;

        $catalogId = $nextObj++; // 1
        $pagesId   = $nextObj++; // 2
        $f1Id      = $nextObj++; // 3: Helvetica
        $f2Id      = $nextObj++; // 4: Helvetica-Bold
        $f3Id      = $nextObj++; // 5: Courier
        $imId      = $logoRgb ? $nextObj++ : null;

        $pageIds = [];
        $pageSpecs = [];
        foreach ($pages as $stream) {
            $pId = $nextObj++;
            $cId = $nextObj++;
            $pageIds[] = $pId;
            $pageSpecs[] = ['pId' => $pId, 'cId' => $cId, 'stream' => $stream];
        }

        $kidsStr = implode(' ', array_map(function ($id) { return $id . ' 0 R'; }, $pageIds));
        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId]   = "<< /Type /Pages /Kids [ {$kidsStr} ] /Count " . count($pageIds) . " >>";
        $objects[$f1Id]      = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$f2Id]      = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $objects[$f3Id]      = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>";

        $xobjRes = '';
        if ($logoRgb !== null) {
            $filterPart = $logoRgb['compressed'] ? '/Filter /FlateDecode ' : '';
            $imLen = strlen($logoRgb['data']);
            $objects[$imId] = "<< /Type /XObject /Subtype /Image /Width {$logoRgb['w']} /Height {$logoRgb['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 {$filterPart}/Length {$imLen} >>\nstream\n" . $logoRgb['data'] . "\nendstream";
            $xobjRes = "/XObject << /Im1 {$imId} 0 R >> ";
        }

        $resDict = "<< /Font << /F1 {$f1Id} 0 R /F2 {$f2Id} 0 R /F3 {$f3Id} 0 R >> {$xobjRes}>>";
        foreach ($pageSpecs as $ps) {
            $rawContent = $ps['stream'];
            $useZip = function_exists('gzcompress');
            $cData = $useZip ? gzcompress($rawContent, 6) : $rawContent;
            $cFilter = $useZip ? '/Filter /FlateDecode ' : '';
            $cLen = strlen($cData);

            $objects[$ps['pId']] = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>",
                $pagesId,
                $pageWpt,
                $pageHpt,
                $resDict,
                $ps['cId']
            );
            $objects[$ps['cId']] = "<< {$cFilter}/Length {$cLen} >>\nstream\n{$cData}\nendstream";
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $objNum => $body) {
            $offsets[$objNum] = strlen($pdf);
            $pdf .= "{$objNum} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $totalObjs = count($objects) + 1;
        $pdf .= "xref\n0 {$totalObjs}\n0000000000 65535 f \n";
        for ($i = 1; $i < $totalObjs; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $trailer = "<< /Size {$totalObjs} /Root {$catalogId} 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
        $pdf .= $trailer;

        return $pdf;
    }
}
