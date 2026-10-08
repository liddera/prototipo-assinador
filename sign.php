<?php
/**
 * Protótipo: assina PDF existente com tecnickcom/tc-lib-pdf-sign (revisão incremental).
 * Uso: php sign.php entrada.pdf saida.pdf [perfil] [pfx] [senha]
 * Limitação: writer incremental mínimo, só para PDFs com xref clássico (sem object streams).
 */
require __DIR__.'/vendor/autoload.php';

use Com\Tecnick\Pdf\Sign\Config;
use Com\Tecnick\Pdf\Sign\Signer;

[$script, $in, $out] = $argv + [null, null, null];
$profile = $argv[3] ?? 'pades-b-b';
$pfxPath = $argv[4] ?? __DIR__.'/certs/teste.pfx';
$pfxPass = $argv[5] ?? 'teste123';
$placeholderBytes = 16384; // espaço reservado para o CMS (binário)

$pdf = file_get_contents($in);

// 1. PFX lido em memória (nada de chave em disco)
openssl_pkcs12_read(file_get_contents($pfxPath), $p12, $pfxPass) || throw new RuntimeException('PFX inválido');
$key = openssl_pkey_get_private($p12['pkey']);
$certPem = $p12['cert'];
$certDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $certPem));
$chain = array_map(
    fn ($pem) => base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem)),
    $p12['extracerts'] ?? []
);

// 2. Estado atual do PDF
preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $pdf, $m);
$prevXref = (int) end($m[1]);
preg_match_all('/\/Size\s+(\d+)/', $pdf, $m);
$size = (int) end($m[1]);
preg_match('/\/Root\s+(\d+) 0 R/', $pdf, $m);
$rootNum = (int) $m[1];

$latest = function (int $n) use ($pdf): string {
    preg_match_all('/(?<=\n|^)'.$n.' 0 obj\s*(.*?)\s*endobj/s', $pdf, $mm);
    return end($mm[1]);
};

$catalog = $latest($rootNum);
preg_match('/\/Pages\s+(\d+) 0 R/', $catalog, $m);
$pagesNum = (int) $m[1];
preg_match('/\/Kids\s*\[\s*(\d+) 0 R/', $latest($pagesNum), $m);
$pageNum = (int) $m[1];
$page = $latest($pageNum);

$sigNum = $size;
$widgetNum = $size + 1;
$fieldName = 'Assinatura'.($size);

// 3. Objetos novos / alterados
$byteRangePh = '/ByteRange [0000000000 0000000000 0000000000 0000000000]';
$contentsPh = '/Contents <'.str_repeat('0', $placeholderBytes * 2).'>';
$subFilter = (new Config($profile))->subFilter();
$signDate = 'D:'.gmdate('YmdHis').'Z';

$objSig = "$sigNum 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /$subFilter $byteRangePh $contentsPh /M ($signDate) /Name (EMPRESA TESTE LTDA) /Reason (Teste de laboratorio) >>\nendobj\n";
$objWidget = "$widgetNum 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Sig /T ($fieldName) /V $sigNum 0 R /Rect [0 0 0 0] /F 132 /P $pageNum 0 R >>\nendobj\n";

// Page: adiciona o widget em /Annots
if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $am)) {
    $newPage = str_replace($am[0], '/Annots ['.trim($am[1])." $widgetNum 0 R]", $page);
} else {
    $newPage = preg_replace('/>>\s*$/', "/Annots [$widgetNum 0 R] >>", $page);
}
// Catalog: AcroForm /Fields
if (preg_match('/\/Fields\s*\[(.*?)\]/s', $catalog, $fm)) {
    $newCatalog = str_replace($fm[0], '/Fields ['.trim($fm[1])." $widgetNum 0 R]", $catalog);
} else {
    $newCatalog = preg_replace('/>>\s*$/', "/AcroForm << /Fields [$widgetNum 0 R] /SigFlags 3 >> >>", $catalog);
}
$objPage = "$pageNum 0 obj\n$newPage\nendobj\n";
$objCatalog = "$rootNum 0 obj\n$newCatalog\nendobj\n";

// 4. Monta a revisão incremental
$body = "\n";
$base = strlen($pdf);
$offsets = [];
foreach ([$rootNum => $objCatalog, $pageNum => $objPage, $sigNum => $objSig, $widgetNum => $objWidget] as $n => $o) {
    $offsets[$n] = $base + strlen($body);
    $body .= $o;
}
ksort($offsets);
$xrefOffset = $base + strlen($body);
$xref = "xref\n";
foreach ($offsets as $n => $off) {
    $xref .= "$n 1\n".sprintf('%010d 00000 n ', $off)."\n";
}
$newSize = $size + 2;
$xref .= "trailer\n<< /Size $newSize /Root $rootNum 0 R /Prev $prevXref >>\nstartxref\n$xrefOffset\n%%EOF\n";
$final = $pdf.$body.$xref;

// 5. ByteRange real
$contentsPos = strpos($final, $contentsPh);
$sigStart = $contentsPos + strlen('/Contents ');
$sigEnd = $sigStart + $placeholderBytes * 2 + 2; // inclui < >
$br = sprintf('/ByteRange [%010d %010d %010d %010d]', 0, $sigStart, $sigEnd, strlen($final) - $sigEnd);
assert(strlen($br) === strlen($byteRangePh));
$final = str_replace($byteRangePh, $br, $final);

$signedContent = substr($final, 0, $sigStart).substr($final, $sigEnd);

// 6. CMS via lib
$signer = new Signer();
$timestamp = null;
$transport = null;
if ($profile !== 'pades-b-b' && $profile !== 'legacy') {
    $timestamp = new Com\Tecnick\Pdf\Sign\Timestamp\Client(new Com\Tecnick\Pdf\Sign\Timestamp\Config(host: 'https://freetsa.org/tsr'));
    $transport = function (string $req): string {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/timestamp-query\r\n",
            'content' => $req, 'timeout' => 20,
        ]]);
        $r = file_get_contents('https://freetsa.org/tsr', false, $ctx);
        return $r === false ? throw new RuntimeException('TSA inacessível') : $r;
    };
}
$cms = $signer->sign($signedContent, $certDer, $key, $chain, new Config($profile), time(), $timestamp, $transport);

if (strlen($cms) > $placeholderBytes) {
    throw new RuntimeException('CMS maior que o espaço reservado: '.strlen($cms));
}
$hex = str_pad(bin2hex($cms), $placeholderBytes * 2, '0');
$final = substr($final, 0, $sigStart + 1).$hex.substr($final, $sigEnd - 1);

file_put_contents($out, $final);
echo "OK: $out (".strlen($final)." bytes, CMS ".strlen($cms)." bytes, perfil $profile)\n";
