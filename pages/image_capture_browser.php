<?php
declare(strict_types=1);

/**
 * Navegador de captura de imagens (equivalente web ao sBrowser do SORGES).
 * Sem Google Cloud: busca Bing Imagens (pt-BR) + cola URL + upload local.
 */
use Delivery\Utils\Auth;
use Delivery\Utils\View;

if (!Auth::check() || !Auth::can('nav.products')) {
    http_response_code(403);
    echo 'Sem permissão';
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$apiBase = View::path('/cardapio/imagens');
$csrf = View::csrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Navegador de captura — imagens</title>
    <style>
        :root {
            --bg: #1c1410;
            --panel: #2a1f18;
            --surface: #fffdf9;
            --accent: #b71c1c;
            --muted: #6b5b4f;
            --border: #e6ddd2;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: #f5efe8;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .bar {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            padding: 0.65rem 0.75rem;
            background: var(--panel);
            border-bottom: 1px solid #3d2e24;
            flex-wrap: wrap;
        }
        .bar strong { white-space: nowrap; font-size: 0.9rem; }
        .bar input[type="search"], .bar input[type="url"] {
            flex: 1;
            min-width: 160px;
            padding: 0.45rem 0.6rem;
            border-radius: 8px;
            border: 1px solid #4a3a2e;
            background: #120e0b;
            color: #fff;
        }
        .bar button {
            border: 0;
            border-radius: 8px;
            padding: 0.5rem 0.85rem;
            cursor: pointer;
            font-weight: 700;
            background: var(--accent);
            color: #fff;
        }
        .bar button.secondary { background: #4a3a2e; }
        .help {
            margin: 0;
            padding: 0.55rem 0.85rem;
            font-size: 0.82rem;
            color: #cbb9a8;
            background: #241a14;
        }
        .status {
            margin: 0;
            padding: 0.35rem 0.85rem;
            font-size: 0.8rem;
            color: #e8c547;
            min-height: 1.4em;
        }
        .grid {
            flex: 1;
            overflow: auto;
            padding: 0.75rem;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.65rem;
            align-content: start;
            background: #120e0b;
        }
        .card {
            margin: 0;
            padding: 0;
            border: 1px solid #3d2e24;
            border-radius: 10px;
            overflow: hidden;
            background: #1a1410;
            cursor: pointer;
            text-align: left;
            color: inherit;
            font: inherit;
        }
        .card:hover { border-color: var(--accent); outline: 2px solid rgba(183,28,28,.45); }
        .card img {
            display: block;
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            background: #2a1f18;
        }
        .card span {
            display: block;
            padding: 0.35rem 0.45rem;
            font-size: 0.7rem;
            color: #b8a99a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .foot {
            padding: 0.65rem 0.75rem;
            background: var(--panel);
            border-top: 1px solid #3d2e24;
            display: grid;
            gap: 0.45rem;
        }
        .foot-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .empty {
            grid-column: 1 / -1;
            text-align: center;
            color: #9a8b7c;
            padding: 2rem 1rem;
        }
    </style>
</head>
<body>
    <div class="bar">
        <strong>🖼 Captura</strong>
        <input type="search" id="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex.: pizza de calabresa, refrigerante guaraná…">
        <button type="button" class="secondary" id="btnBing" title="Abrir Bing Imagens">Bing</button>
        <button type="button" id="btnSearch">Buscar</button>
        <button type="button" class="secondary" id="btnClose">Fechar</button>
    </div>
    <p class="help">
        Fonte: <strong>Bing Imagens</strong> (pt-BR) — fotos de <strong>produto embalado</strong> (lata/garrafa), não fruto/planta.
        Clique na foto ou cole o link do Bing / URL direta → o sistema reduz para <strong>320×320</strong>.
    </p>
    <p class="status" id="status"></p>
    <div class="grid" id="grid">
        <p class="empty">Digite um termo e clique em Buscar.</p>
    </div>
    <div class="foot">
        <div class="foot-row">
            <input type="url" id="url" placeholder="Cole URL da imagem ou link do Bing (detail)">
            <button type="button" id="btnUrl">Usar URL</button>
        </div>
        <div class="foot-row">
            <input type="file" id="file" accept="image/*">
        </div>
    </div>
<script>
(function () {
  var API = <?= json_encode($apiBase, JSON_UNESCAPED_SLASHES) ?>;
  var CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
  var qEl = document.getElementById('q');
  var grid = document.getElementById('grid');
  var status = document.getElementById('status');

  function setStatus(t) { status.textContent = t || ''; }

  function sendToOpener(payload) {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(Object.assign({ source: 'delivery-image-capture' }, payload), window.location.origin);
      setStatus('Imagem enviada ao cadastro. Você pode fechar esta janela.');
      try { window.close(); } catch (e) {}
      return true;
    }
    setStatus('Abra este navegador a partir do Cardápio (botão Buscar na internet).');
    return false;
  }

  function doSearch() {
    var q = (qEl.value || '').trim();
    if (q.length < 2) {
      setStatus('Digite ao menos 2 caracteres.');
      return;
    }
    setStatus('Buscando…');
    grid.innerHTML = '';
    fetch(API + '?action=search&q=' + encodeURIComponent(q), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) {
          setStatus(data.message || 'Falha na busca.');
          grid.innerHTML = '<p class="empty">Nenhum resultado. Tente outro termo ou cole uma URL.</p>';
          return;
        }
        setStatus((data.count || 0) + ' imagem(ns). Clique para capturar.');
        if (!data.results || !data.results.length) {
          grid.innerHTML = '<p class="empty">Nenhuma imagem. Tente outro termo ou cole uma URL abaixo.</p>';
          return;
        }
        data.results.forEach(function (item) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'card';
          btn.innerHTML = '<img loading="lazy" alt=""><span></span>';
          btn.querySelector('img').src = item.thumb;
          btn.querySelector('span').textContent = (item.source || '') + ' · ' + (item.title || '');
          btn.addEventListener('click', function () {
            setStatus('Capturando…');
            sendToOpener({ action: 'pick', url: item.full, title: item.title || '' });
          });
          grid.appendChild(btn);
        });
      })
      .catch(function () {
        setStatus('Erro de rede na busca.');
      });
  }

  document.getElementById('btnSearch').addEventListener('click', doSearch);
  document.getElementById('btnBing').addEventListener('click', function () {
    var q = (qEl.value || '').trim() || 'produto';
    var url = 'https://www.bing.com/images/search?q=' + encodeURIComponent(q) +
      '&form=HDRSC2&setlang=pt-br&cc=BR&mkt=pt-BR';
    window.open(url, '_blank', 'noopener');
    setStatus('Bing Imagens aberto. Clique com o botão direito na foto → Copiar endereço da imagem → cole abaixo.');
  });
  qEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
  });
  document.getElementById('btnClose').addEventListener('click', function () {
    try { window.close(); } catch (e) {}
  });
  function unwrapImageUrl(u) {
    try {
      var a = document.createElement('a');
      a.href = u;
      if (!/bing\.(com|net)/i.test(a.hostname)) return u;
      var params = new URLSearchParams(a.search);
      var keys = ['mediaurl', 'imgurl', 'rurl'];
      for (var i = 0; i < keys.length; i++) {
        var v = params.get(keys[i]);
        if (v && /^https?:\/\//i.test(v)) return v;
      }
    } catch (e) {}
    return u;
  }

  document.getElementById('btnUrl').addEventListener('click', function () {
    var u = (document.getElementById('url').value || '').trim();
    if (!/^https?:\/\//i.test(u)) {
      setStatus('Cole uma URL http(s) válida.');
      return;
    }
    u = unwrapImageUrl(u);
    sendToOpener({ action: 'pick', url: u });
  });
  document.getElementById('file').addEventListener('change', function (e) {
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function () {
      sendToOpener({ action: 'pick-data', dataUrl: reader.result, name: f.name });
    };
    reader.readAsDataURL(f);
  });

  if ((qEl.value || '').trim().length >= 2) {
    doSearch();
  }
})();
</script>
</body>
</html>
