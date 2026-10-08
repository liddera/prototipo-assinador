<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/PdfSigner.php';

const OUT_PATTERN = '/^assinado-[0-9a-f]{8}\.pdf$/';
const MAX_SIGNERS = 4;

if (isset($_GET['download']) || isset($_GET['view'])) {
    $inline = isset($_GET['view']);
    $f = basename($_GET['view'] ?? $_GET['download']);
    $path = __DIR__.'/../out/'.$f;
    if (preg_match(OUT_PATTERN, $f) && is_file($path)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$f.'"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Não encontrado');
}

$error = null;
$result = null;
$prev = isset($_GET['continue']) && preg_match(OUT_PATTERN, $_GET['continue']) ? $_GET['continue'] : '';

/**
 * Lê o PFX em memória e devolve os dados públicos do certificado.
 *
 * @return array{cn: string, doc: string, emissor: string, emissao: string, validade: string, vencido: bool}
 */
function certInfo(string $pfxBytes, string $pass): array
{
    if (! openssl_pkcs12_read($pfxBytes, $p, $pass)) {
        throw new RuntimeException('Não foi possível abrir o certificado. Confira a senha e se o arquivo é um .pfx/.p12.');
    }
    $x = openssl_x509_parse($p['cert']);
    preg_match('/\d{14}|\d{11}/', $x['subject']['CN'] ?? '', $doc);

    return [
        'cn' => $x['subject']['CN'] ?? '-',
        'doc' => $doc[0] ?? '-',
        'emissor' => $x['issuer']['CN'] ?? '-',
        'emissao' => date('d/m/Y', $x['validFrom_time_t']),
        'validade' => date('d/m/Y', $x['validTo_time_t']),
        'vencido' => $x['validTo_time_t'] < time() || $x['validFrom_time_t'] > time(),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $prevPost = (string) ($_POST['prev'] ?? '');
        $pdfUp = $_FILES['pdf'] ?? null;
        if ($pdfUp && $pdfUp['error'] === UPLOAD_ERR_OK) {
            $pdf = file_get_contents($pdfUp['tmp_name']);
            $pdfNome = $pdfUp['name'];
        } elseif (preg_match(OUT_PATTERN, $prevPost) && is_file(__DIR__.'/../out/'.$prevPost)) {
            $pdf = file_get_contents(__DIR__.'/../out/'.$prevPost);
            $pdfNome = $prevPost;
            $prev = $prevPost;
        } else {
            throw new RuntimeException('Envie o PDF que será assinado.');
        }

        $posted = array_slice(array_values((array) ($_POST['signers'] ?? [])), 0, MAX_SIGNERS);
        if ($posted === []) {
            throw new RuntimeException('Informe ao menos um certificado.');
        }
        $profile = ($_POST['perfil'] ?? '') === 'pades-b-t' ? 'pades-b-t' : 'pades-b-b';

        // Valida todos os certificados antes de assinar qualquer coisa.
        $plan = [];
        foreach ($posted as $i => $s) {
            $label = 'Assinante '.($i + 1);
            $err = $_FILES['signers']['error'][$i]['pfx'] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException("$label: envie o arquivo do certificado (.pfx ou .p12).");
            }
            $pfxBytes = file_get_contents($_FILES['signers']['tmp_name'][$i]['pfx']);
            $pass = (string) ($s['senha'] ?? '');
            try {
                $info = certInfo($pfxBytes, $pass);
            } catch (RuntimeException $e) {
                throw new RuntimeException("$label: ".$e->getMessage());
            }
            if ($info['vencido']) {
                throw new RuntimeException("$label: certificado fora da validade ({$info['emissao']} a {$info['validade']}).");
            }
            $placement = null;
            if (empty($s['invisivel']) && (int) ($s['page'] ?? 0) > 0) {
                $placement = [
                    'page' => (int) $s['page'],
                    'x' => (float) $s['px'], 'y' => (float) $s['py'],
                    'w' => (float) $s['pw'], 'h' => (float) $s['ph'],
                ];
            }
            $plan[] = compact('pfxBytes', 'pass', 'info', 'placement');
        }

        // Assina em sequência: cada assinatura é uma revisão incremental sobre a anterior.
        $signer = new Lab\PdfSigner();
        $signed = $pdf;
        $signers = [];
        foreach ($plan as $i => $p) {
            try {
                $signed = $signer->sign($signed, $p['pfxBytes'], $p['pass'], $profile, $p['placement']);
            } catch (Throwable $e) {
                throw new RuntimeException('Assinante '.($i + 1).': '.$e->getMessage());
            }
            $signers[] = ['info' => $p['info'], 'visivel' => $p['placement'] !== null];
        }
        unset($plan, $pfxBytes, $pass);

        $name = 'assinado-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents(__DIR__.'/../out/'.$name, $signed);
        $result = [
            'name' => $name, 'profile' => $profile, 'signers' => $signers,
            'pdf_nome' => $pdfNome,
            'sha_in' => hash('sha256', $pdf), 'sha_out' => hash('sha256', $signed),
            'size' => strlen($signed),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Assinador PDF (laboratório)</title>
<style>
:root{--bg:#f6f7f9;--fg:#1d2330;--card:#fff;--bd:#d9dde5;--ac:#2456d6;--warn:#b45309;--err:#b42318;--ok:#067647;--mut:#5b6475;--c0:#2456d6;--c1:#0e8a5f;--c2:#b45309;--c3:#8a2be2}
@media (prefers-color-scheme:dark){:root{--bg:#12151c;--fg:#e8ebf2;--card:#1b202b;--bd:#2c3341;--ac:#6d93ff;--warn:#f0b35a;--err:#ff8a80;--ok:#5fd39a;--mut:#9aa3b5;--c0:#6d93ff;--c1:#4fd1a1;--c2:#f0b35a;--c3:#c39bff}}
*{box-sizing:border-box}
body{font:16px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--fg);margin:0;padding:24px 16px}
main{max-width:720px;margin:0 auto}
.card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:20px;margin-bottom:16px}
h1{font-size:1.3rem;margin:0 0 4px}.sub{color:var(--mut);font-size:.9rem;margin-bottom:6px}
.warn{color:var(--warn);font-size:.85rem;margin-bottom:8px}
.step{display:flex;gap:12px;margin-top:20px}
.n{flex:none;width:28px;height:28px;border-radius:50%;background:var(--ac);color:#fff;display:grid;place-items:center;font-weight:700;font-size:.9rem}
.step>div{flex:1;min-width:0}
label{display:block;font-weight:600;margin-bottom:6px}
.hint{color:var(--mut);font-size:.85rem;margin-top:4px}
input[type=file],input[type=password],select{width:100%;padding:9px;border:1px solid var(--bd);border-radius:8px;background:var(--bg);color:var(--fg);font:inherit}
button{font:inherit;cursor:pointer}
.go{margin-top:24px;width:100%;background:var(--ac);color:#fff;border:0;border-radius:10px;padding:12px;font-size:1rem;font-weight:600}
.err{color:var(--err)}.ok{color:var(--ok)}code{word-break:break-all;font-size:.78rem}
dl{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:10px 0}dt{color:var(--mut)}dd{margin:0;word-break:break-word}
ol{padding-left:20px;margin:8px 0}
.btnlink{display:inline-block;margin-right:12px;font-weight:600}
#pages{margin-top:10px;display:flex;flex-direction:column;gap:12px;align-items:center}
.pg{position:relative;box-shadow:0 1px 6px rgba(0,0,0,.25);cursor:crosshair;background:#fff;touch-action:none}
.pg canvas{display:block}
.pgn{position:absolute;top:4px;left:6px;font-size:.7rem;background:rgba(0,0,0,.55);color:#fff;border-radius:4px;padding:0 6px;pointer-events:none}
.box{position:absolute;border:2px solid var(--bc);background:color-mix(in srgb,var(--bc) 14%,transparent);cursor:move;font-size:10px;color:#12306e;line-height:1.25;padding:3px 5px;overflow:hidden;touch-action:none;user-select:none}
.box .h{position:absolute;right:0;bottom:0;width:14px;height:14px;background:var(--bc);cursor:nwse-resize}
.sg{border:1px solid var(--bd);border-left:5px solid var(--bc);border-radius:10px;padding:14px;margin-top:12px}
.sg-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.sg-head strong{color:var(--bc)}
.mini{background:transparent;border:1px solid var(--bd);color:var(--fg);border-radius:8px;padding:4px 10px;font-size:.85rem}
.mini.on{background:var(--bc);border-color:var(--bc);color:#fff}
.row{margin-top:10px}
.pick{display:flex;align-items:center;gap:8px;font-weight:400;margin:8px 0 0}
.pick input{width:auto}
#add{margin-top:12px;background:transparent;border:1px dashed var(--ac);color:var(--ac);border-radius:10px;padding:9px 14px;width:100%;font-weight:600}
#placeMsg{margin-top:6px}
</style></head><body><main>
<div class="card">
  <h1>Assinar PDF com certificado digital</h1>
  <div class="sub">Laboratório local, fora do Alvras.</div>
  <div class="warn">Os certificados e as senhas ficam só na memória durante a assinatura. Nada é gravado, só o PDF assinado.</div>
  <form method="post" enctype="multipart/form-data" id="form">
    <input type="hidden" name="prev" id="prev" value="<?= $h($prev) ?>">

    <div class="step"><span class="n">1</span><div>
      <?php if ($prev): ?>
        <label>PDF já assinado (continuando)</label>
        <div class="hint">Usando <strong><?= $h($prev) ?></strong>. As novas assinaturas entram por cima, sem apagar as anteriores.
          <a href="./">Começar com outro PDF</a></div>
      <?php else: ?>
        <label for="pdf">PDF para assinar</label>
        <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" required>
      <?php endif; ?>
    </div></div>

    <div class="step"><span class="n">2</span><div>
      <label>Quem vai assinar</label>
      <div class="hint">Cada assinante usa o seu certificado e a sua senha. A ordem da lista é a ordem das assinaturas no PDF.</div>
      <div id="signers"></div>
      <button type="button" id="add">+ Adicionar outro assinante</button>
    </div></div>

    <div class="step"><span class="n">3</span><div>
      <label>Onde cada assinatura vai aparecer</label>
      <div class="hint">Clique em "Posicionar" no assinante e depois clique na página. Arraste o quadro para mover e use o canto para redimensionar.</div>
      <div id="pages"><div class="hint">Envie o PDF para ver as páginas aqui.</div></div>
      <div id="placeMsg" class="hint"></div>
    </div></div>

    <div class="step"><span class="n">4</span><div>
      <label for="perfil">Tipo de assinatura</label>
      <select id="perfil" name="perfil">
        <option value="pades-b-b">PAdES B-B (simples, só offline)</option>
        <option value="pades-b-t">PAdES B-T (com carimbo do tempo de teste — ainda não funciona)</option>
      </select>
    </div></div>
    <button type="submit" class="go">Assinar PDF</button>
  </form>
</div>

<?php if ($error): ?><div class="card err"><strong>Erro:</strong> <?= $h($error) ?></div><?php endif; ?>

<?php if ($result): ?>
<div class="card">
  <strong class="ok">PDF assinado com sucesso (<?= count($result['signers']) ?> assinatura<?= count($result['signers']) > 1 ? 's' : '' ?>).</strong>
  <dl>
    <dt>Arquivo</dt><dd><?= $h($result['pdf_nome']) ?> · <?= number_format($result['size']) ?> bytes</dd>
    <dt>Perfil</dt><dd><?= $h($result['profile']) ?></dd>
    <?php foreach ($result['signers'] as $k => $s): $i = $s['info']; ?>
      <dt>Assinante <?= $k + 1 ?></dt>
      <dd><?= $h($i['cn']) ?><br><small>CPF/CNPJ <?= $h($i['doc']) ?> · emissor <?= $h($i['emissor']) ?> · válido até <?= $h($i['validade']) ?> · <?= $s['visivel'] ? 'visível' : 'invisível' ?></small></dd>
    <?php endforeach; ?>
  </dl>
  <p>
    <a class="btnlink" href="?download=<?= $h($result['name']) ?>">Baixar PDF assinado</a>
    <a class="btnlink" href="?continue=<?= $h($result['name']) ?>">Assinar mais uma vez</a>
  </p>
  <p>SHA-256 original:<br><code><?= $h($result['sha_in']) ?></code></p>
  <p>SHA-256 assinado:<br><code><?= $h($result['sha_out']) ?></code></p>
</div>
<div class="card">
  <strong>Como saber se as assinaturas são válidas</strong>
  <ol>
    <li>Baixe o PDF assinado.</li>
    <li>Envie em <a href="https://validar.iti.gov.br" target="_blank" rel="noopener">validar.iti.gov.br</a>. Ele deve listar uma assinatura para cada assinante.</li>
    <li>Como segunda checagem, abra o PDF no Adobe Reader e veja o painel de assinaturas.</li>
  </ol>
</div>
<?php endif; ?>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(() => {
  pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  const MAX = <?= MAX_SIGNERS ?>;
  const $ = (id) => document.getElementById(id);
  const pagesEl = $('pages'), form = $('form'), msg = $('placeMsg'), list = $('signers');
  const signers = []; // { el, box, wrap, invisible, f: {page,px,py,pw,ph} }
  let active = 0, hasPdf = false;

  function addSigner() {
    if (signers.length >= MAX) return;
    const i = signers.length;
    const el = document.createElement('div');
    el.className = 'sg'; el.style.setProperty('--bc', 'var(--c' + (i % 4) + ')');
    el.innerHTML = `
      <div class="sg-head"><strong>Assinante ${i + 1}</strong>
        <span><button type="button" class="mini pos">Posicionar</button>
        ${i > 0 ? '<button type="button" class="mini rem">Remover</button>' : ''}</span></div>
      <label>Certificado (.pfx / .p12)</label>
      <input type="file" name="signers[${i}][pfx]" accept=".pfx,.p12,application/x-pkcs12" required>
      <div class="row"><label>Senha do certificado</label>
      <input type="password" name="signers[${i}][senha]" autocomplete="off" required></div>
      <label class="pick"><input type="checkbox" name="signers[${i}][invisivel]" value="1" class="inv"> Assinatura invisível</label>
      ${['page', 'px', 'py', 'pw', 'ph'].map((k) => `<input type="hidden" name="signers[${i}][${k}]" data-k="${k}" value="0">`).join('')}`;
    list.appendChild(el);
    const s = { el, box: null, wrap: null, invisible: false, f: {} };
    el.querySelectorAll('[data-k]').forEach((x) => { s.f[x.dataset.k] = x; });
    el.querySelector('.pos').addEventListener('click', () => select(i));
    el.querySelector('.inv').addEventListener('change', (e) => {
      s.invisible = e.target.checked;
      if (s.box) s.box.style.display = s.invisible ? 'none' : '';
      sync(s);
    });
    const rem = el.querySelector('.rem');
    if (rem) rem.addEventListener('click', () => removeLast(i));
    signers.push(s);
    select(i);
    $('add').style.display = signers.length >= MAX ? 'none' : '';
  }

  function removeLast(i) {
    if (i !== signers.length - 1) return;
    const s = signers.pop();
    if (s.box) s.box.remove();
    s.el.remove();
    select(Math.min(active, signers.length - 1));
    $('add').style.display = '';
  }

  function select(i) {
    active = i;
    signers.forEach((s, k) => s.el.querySelector('.pos').classList.toggle('on', k === i));
    msg.textContent = hasPdf ? 'Posicionando o Assinante ' + (i + 1) + ': clique na página.' : '';
  }

  function sync(s) {
    if (!s.box || s.invisible) { s.f.page.value = 0; return; }
    const W = s.wrap.clientWidth, H = s.wrap.clientHeight;
    s.f.page.value = s.wrap.dataset.page;
    s.f.px.value = s.box.offsetLeft / W;
    s.f.py.value = s.box.offsetTop / H;
    s.f.pw.value = s.box.offsetWidth / W;
    s.f.ph.value = s.box.offsetHeight / H;
  }

  function startDrag(e, s, resize) {
    e.preventDefault(); e.stopPropagation();
    const box = s.box, W = s.wrap.clientWidth, H = s.wrap.clientHeight;
    const sx = e.clientX, sy = e.clientY, ol = box.offsetLeft, ot = box.offsetTop, ow = box.offsetWidth, oh = box.offsetHeight;
    const move = (m) => {
      const dx = m.clientX - sx, dy = m.clientY - sy;
      if (resize) {
        box.style.width = Math.max(70, Math.min(W - ol, ow + dx)) + 'px';
        box.style.height = Math.max(36, Math.min(H - ot, oh + dy)) + 'px';
      } else {
        box.style.left = Math.max(0, Math.min(W - box.offsetWidth, ol + dx)) + 'px';
        box.style.top = Math.max(0, Math.min(H - box.offsetHeight, ot + dy)) + 'px';
      }
      sync(s);
    };
    const up = () => { removeEventListener('pointermove', move); removeEventListener('pointerup', up); };
    addEventListener('pointermove', move); addEventListener('pointerup', up);
  }

  function place(w, e) {
    const s = signers[active];
    if (!s || s.invisible) return;
    if (!s.box) {
      s.box = document.createElement('div');
      s.box.className = 'box'; s.box.style.setProperty('--bc', 'var(--c' + (active % 4) + ')');
      s.box.innerHTML = `<strong>Assinante ${active + 1}</strong><br>assinado digitalmente<div class="h"></div>`;
      s.box.addEventListener('pointerdown', (ev) => startDrag(ev, s, ev.target.classList.contains('h')));
    }
    s.wrap = w; w.appendChild(s.box);
    const r = w.getBoundingClientRect();
    const bw = Math.min(210, w.clientWidth * 0.5), bh = bw * 0.33;
    s.box.style.width = bw + 'px'; s.box.style.height = bh + 'px';
    s.box.style.left = Math.max(0, Math.min(w.clientWidth - bw, e.clientX - r.left - bw / 2)) + 'px';
    s.box.style.top = Math.max(0, Math.min(w.clientHeight - bh, e.clientY - r.top - bh / 2)) + 'px';
    sync(s);
    msg.textContent = 'Assinante ' + (active + 1) + ' na página ' + w.dataset.page + '.';
  }

  async function loadPdf(data) {
    pagesEl.innerHTML = '<div class="hint">Carregando páginas…</div>';
    signers.forEach((s) => { if (s.box) { s.box.remove(); s.box = null; s.wrap = null; } s.f.page.value = 0; });
    try {
      const pdf = await pdfjsLib.getDocument({ data }).promise;
      pagesEl.innerHTML = '';
      const width = Math.min(pagesEl.clientWidth || 640, 680);
      const total = Math.min(pdf.numPages, 40);
      for (let i = 1; i <= total; i++) {
        const page = await pdf.getPage(i);
        const base = page.getViewport({ scale: 1 });
        const vp = page.getViewport({ scale: width / base.width });
        const w = document.createElement('div');
        w.className = 'pg'; w.dataset.page = i;
        w.style.width = vp.width + 'px'; w.style.height = vp.height + 'px';
        const c = document.createElement('canvas');
        c.width = vp.width; c.height = vp.height;
        w.appendChild(c);
        const n = document.createElement('div'); n.className = 'pgn'; n.textContent = 'Página ' + i;
        w.appendChild(n);
        w.addEventListener('pointerdown', (e) => { if (e.target === c || e.target === w) place(w, e); });
        pagesEl.appendChild(w);
        await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
      }
      if (pdf.numPages > total) pagesEl.insertAdjacentHTML('beforeend', '<div class="hint">Mostrando as primeiras ' + total + ' páginas.</div>');
      hasPdf = true;
      select(active);
    } catch (err) {
      pagesEl.innerHTML = '<div class="err">Não foi possível exibir o PDF: ' + err.message + '</div>';
    }
  }

  $('add').addEventListener('click', addSigner);
  addSigner();

  const pdfInput = $('pdf');
  if (pdfInput) {
    pdfInput.addEventListener('change', async () => {
      if (pdfInput.files[0]) loadPdf(new Uint8Array(await pdfInput.files[0].arrayBuffer()));
    });
  }
  const prev = $('prev').value;
  if (prev) fetch('?view=' + encodeURIComponent(prev)).then((r) => r.arrayBuffer()).then((b) => loadPdf(new Uint8Array(b)));

  form.addEventListener('submit', (e) => {
    const missing = signers.findIndex((s) => hasPdf && !s.invisible && !s.box);
    if (missing >= 0) {
      e.preventDefault();
      select(missing);
      msg.innerHTML = '<span class="err">Posicione o Assinante ' + (missing + 1) + ' na página (clique no PDF) ou marque "Assinatura invisível".</span>';
      pagesEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();
</script>
</body></html>
