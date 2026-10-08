<?php

namespace Lab;

use Com\Tecnick\Pdf\Sign\Config;
use Com\Tecnick\Pdf\Sign\Signer;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use Com\Tecnick\Pdf\Sign\Timestamp\Config as TimestampConfig;
use RuntimeException;

/**
 * Assina PDF existente com tc-lib-pdf-sign via revisão incremental.
 * Suporta várias assinaturas em sequência e assinatura visível posicionada.
 * Limitação: writer mínimo, só PDFs com xref clássico (sem object streams).
 */
final class PdfSigner
{
    private const PLACEHOLDER_BYTES = 16384;

    private const TSA_URL = 'https://freetsa.org/tsr';

    /**
     * @param  array{page: int, x: float, y: float, w: float, h: float}|null  $placement  Coordenadas normalizadas (0-1), origem no canto superior esquerdo da página (1-based). Null = assinatura invisível.
     * @return string PDF assinado
     */
    public function sign(string $pdf, string $pfxBytes, string $pfxPass, string $profile = 'pades-b-b', ?array $placement = null): string
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Arquivo não é um PDF.');
        }

        [$key, $certDer, $chain, $certInfo] = $this->loadPfx($pfxBytes, $pfxPass);

        preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $pdf, $m);
        if (empty($m[1])) {
            throw new RuntimeException('PDF sem xref clássico (object streams não suportado no protótipo).');
        }
        $prevXref = (int) end($m[1]);
        preg_match_all('/\/Size\s+(\d+)/', $pdf, $m);
        $size = (int) end($m[1]);
        preg_match('/\/Root\s+(\d+) 0 R/', $pdf, $m);
        $rootNum = (int) $m[1];

        $latest = function (int $n) use ($pdf): string {
            preg_match_all('/(?<=\n|^)'.$n.' 0 obj\s*(.*?)\s*endobj/s', $pdf, $mm);

            return end($mm[1]) ?: throw new RuntimeException("Objeto $n não encontrado.");
        };

        $catalog = $latest($rootNum);
        if (preg_match('/\/AcroForm\s+\d+ 0 R/', $catalog) || preg_match('/\/Fields\s+\d+ 0 R/', $catalog)) {
            throw new RuntimeException('PDF com AcroForm indireto não é suportado no protótipo.');
        }
        preg_match('/\/Pages\s+(\d+) 0 R/', $catalog, $m);
        $pages = $this->pageList($latest, (int) $m[1]);

        $pageIndex = $placement ? max(1, min(count($pages), $placement['page'])) : 1;
        $pageNum = $pages[$pageIndex - 1];
        $page = $latest($pageNum);
        if (preg_match('/\/Annots\s+\d+ 0 R/', $page)) {
            throw new RuntimeException('Página com /Annots indireto não é suportada no protótipo.');
        }
        if (preg_match('/\/Rotate\s+(-?\d+)/', $page, $rm) && ((int) $rm[1]) % 360 !== 0) {
            throw new RuntimeException('Páginas rotacionadas não são suportadas no protótipo.');
        }

        $sigNum = $size;
        $widgetNum = $size + 1;
        $apNum = $size + 2;
        $fieldName = 'Assinatura'.$size;
        $ph = self::PLACEHOLDER_BYTES;

        $byteRangePh = '/ByteRange [0000000000 0000000000 0000000000 0000000000]';
        $contentsPh = '/Contents <'.str_repeat('0', $ph * 2).'>';
        $subFilter = (new Config($profile))->subFilter();
        $signDate = 'D:'.gmdate('YmdHis').'Z';
        $name = $this->escape($this->latin($certInfo['cn']));

        $objSig = "$sigNum 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /$subFilter $byteRangePh $contentsPh /M ($signDate) /Name ($name) >>\nendobj\n";

        $objAp = '';
        if ($placement) {
            [$mx0, $my0, $mx1, $my1] = $this->mediaBox($latest, $pageNum);
            $pw = $mx1 - $mx0;
            $phh = $my1 - $my0;
            $w = max(60.0, $placement['w'] * $pw);
            $h = max(30.0, $placement['h'] * $phh);
            $x1 = $mx0 + min(max(0.0, $placement['x'] * $pw), $pw - $w);
            $y2 = $my1 - min(max(0.0, $placement['y'] * $phh), $phh - $h);
            $y1 = $y2 - $h;
            $rect = sprintf('[%.2f %.2f %.2f %.2f]', $x1, $y1, $x1 + $w, $y2);
            $objAp = $this->appearance($apNum, $w, $h, $certInfo);
            $apEntry = "/AP << /N $apNum 0 R >>";
        } else {
            $rect = '[0 0 0 0]';
            $apEntry = '';
        }
        $objWidget = "$widgetNum 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Sig /T ($fieldName) /V $sigNum 0 R /Rect $rect $apEntry /F 132 /P $pageNum 0 R >>\nendobj\n";

        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $am)) {
            $newPage = str_replace($am[0], '/Annots ['.trim($am[1])." $widgetNum 0 R]", $page);
        } else {
            $newPage = preg_replace('/>>\s*$/', "/Annots [$widgetNum 0 R] >>", $page);
        }
        if (preg_match('/\/Fields\s*\[(.*?)\]/s', $catalog, $fm)) {
            $newCatalog = str_replace($fm[0], '/Fields ['.trim($fm[1])." $widgetNum 0 R]", $catalog);
        } else {
            $newCatalog = preg_replace('/>>\s*$/', "/AcroForm << /Fields [$widgetNum 0 R] /SigFlags 3 >> >>", $catalog);
        }

        $body = "\n";
        $base = strlen($pdf);
        $offsets = [];
        $objects = [
            $rootNum => "$rootNum 0 obj\n$newCatalog\nendobj\n",
            $pageNum => "$pageNum 0 obj\n$newPage\nendobj\n",
            $sigNum => $objSig,
            $widgetNum => $objWidget,
        ];
        if ($objAp !== '') {
            $objects[$apNum] = $objAp;
        }
        foreach ($objects as $n => $o) {
            $offsets[$n] = $base + strlen($body);
            $body .= $o;
        }
        ksort($offsets);
        $xrefOffset = $base + strlen($body);
        $xref = "xref\n";
        foreach ($offsets as $n => $off) {
            $xref .= "$n 1\n".sprintf('%010d 00000 n ', $off)."\n";
        }
        $xref .= "trailer\n<< /Size ".($size + 3)." /Root $rootNum 0 R /Prev $prevXref >>\nstartxref\n$xrefOffset\n%%EOF\n";
        $final = $pdf.$body.$xref;

        $sigStart = strpos($final, $contentsPh) + strlen('/Contents ');
        $sigEnd = $sigStart + $ph * 2 + 2;
        $br = sprintf('/ByteRange [%010d %010d %010d %010d]', 0, $sigStart, $sigEnd, strlen($final) - $sigEnd);
        $final = str_replace($byteRangePh, $br, $final);

        $signedContent = substr($final, 0, $sigStart).substr($final, $sigEnd);

        $timestamp = null;
        $transport = null;
        if (! in_array($profile, ['pades-b-b', 'legacy'], true)) {
            $timestamp = new TimestampClient(new TimestampConfig(host: self::TSA_URL));
            $transport = $this->tsaTransport(...);
        }

        $cms = (new Signer())->sign($signedContent, $certDer, $key, $chain, new Config($profile), time(), $timestamp, $transport);

        if (strlen($cms) > $ph) {
            throw new RuntimeException('CMS maior que o espaço reservado: '.strlen($cms));
        }
        $hex = str_pad(bin2hex($cms), $ph * 2, '0');

        return substr($final, 0, $sigStart + 1).$hex.substr($final, $sigEnd - 1);
    }

    /**
     * Quantidade de páginas e tamanho de cada uma, para a pré-visualização validar o clique.
     *
     * @return list<int> números dos objetos de página, em ordem
     */
    private function pageList(callable $latest, int $pagesNum): array
    {
        $node = $latest($pagesNum);
        if (! preg_match('/\/Kids\s*\[(.*?)\]/s', $node, $m)) {
            throw new RuntimeException('Árvore de páginas com /Kids indireto não é suportada no protótipo.');
        }
        preg_match_all('/(\d+) 0 R/', $m[1], $refs);
        $pages = [];
        foreach ($refs[1] as $ref) {
            $child = $latest((int) $ref);
            if (preg_match('/\/Type\s*\/Pages\b/', $child)) {
                array_push($pages, ...$this->pageList($latest, (int) $ref));
            } else {
                $pages[] = (int) $ref;
            }
        }

        return $pages;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function mediaBox(callable $latest, int $objNum): array
    {
        for ($i = 0; $i < 12; $i++) {
            $obj = $latest($objNum);
            if (preg_match('/\/MediaBox\s*\[([^\]]+)\]/', $obj, $m)) {
                $v = preg_split('/\s+/', trim($m[1]));

                return [(float) $v[0], (float) $v[1], (float) $v[2], (float) $v[3]];
            }
            if (! preg_match('/\/Parent\s+(\d+) 0 R/', $obj, $pm)) {
                break;
            }
            $objNum = (int) $pm[1];
        }

        return [0.0, 0.0, 595.0, 842.0];
    }

    /**
     * @param  array{cn: string, doc: string, nome: string}  $info
     */
    private function appearance(int $apNum, float $w, float $h, array $info): string
    {
        $lines = ['Assinado digitalmente por:', $info['nome']];
        if ($info['doc'] !== '') {
            $lines[] = 'CPF/CNPJ: '.$info['doc'];
        }
        $lines[] = 'Data: '.(new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i:s P');

        $fs = max(5.0, min(9.0, ($h - 10) / (count($lines) * 1.3)));
        $maxChars = max(8, (int) floor(($w - 12) / ($fs * 0.52)));
        $lead = $fs * 1.3;

        $content = sprintf("q 0.96 0.98 1 rg 0 0 %.2f %.2f re f Q\n", $w, $h);
        $content .= sprintf("0.14 0.34 0.84 RG 1 w 0.5 0.5 %.2f %.2f re S\n", $w - 1, $h - 1);
        $content .= sprintf("BT /F1 %.2f Tf 0.1 0.1 0.15 rg %.2f %.2f Td %.2f TL\n", $fs, 6, $h - 5 - $fs, $lead);
        foreach ($lines as $i => $line) {
            $line = $this->latin(mb_strlen($line) > $maxChars ? mb_substr($line, 0, $maxChars - 1).'…' : $line);
            $content .= ($i === 0 ? '' : 'T* ').'('.$this->escape($line).") Tj\n";
        }
        $content .= "ET\n";

        return "$apNum 0 obj\n<< /Type /XObject /Subtype /Form /BBox [0 0 ".sprintf('%.2f %.2f', $w, $h).'] '
            .'/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> >> >> '
            .'/Length '.strlen($content)." >>\nstream\n$content\nendstream\nendobj\n";
    }

    private function latin(string $s): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);

        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: string, 2: list<string>, 3: array{cn: string, doc: string, nome: string}}
     */
    private function loadPfx(string $pfxBytes, string $pass): array
    {
        if (! openssl_pkcs12_read($pfxBytes, $p12, $pass)) {
            throw new RuntimeException('PFX inválido ou senha incorreta.');
        }
        $toDer = fn (string $pem): string => base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));

        $x = openssl_x509_parse($p12['cert']);
        $cn = $x['subject']['CN'] ?? 'Signatario';
        preg_match('/\d{14}|\d{11}/', $cn, $doc);
        $nome = trim(preg_replace('/[:\s]*\d{11,14}\s*$/', '', $cn));

        return [
            openssl_pkey_get_private($p12['pkey']),
            $toDer($p12['cert']),
            array_map($toDer, $p12['extracerts'] ?? []),
            ['cn' => $cn, 'doc' => $doc[0] ?? '', 'nome' => $nome !== '' ? $nome : $cn],
        ];
    }

    private function tsaTransport(string $request): string
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/timestamp-query\r\n",
            'content' => $request,
            'timeout' => 20,
        ]]);
        $response = @file_get_contents(self::TSA_URL, false, $ctx);

        return $response === false ? throw new RuntimeException('TSA inacessível.') : $response;
    }

    private function escape(string $s): string
    {
        return addcslashes($s, '()\\');
    }
}
