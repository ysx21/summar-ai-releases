(function(){
  function initWidget(wrap){
    if (!wrap || wrap.dataset && wrap.dataset.summaraiInit) return;
    if (wrap.dataset) wrap.dataset.summaraiInit = '1';

    var shell  = wrap.querySelector('.ag-llm-shell');
    var input  = wrap.querySelector('.ag-llm-input');
    var btn    = wrap.querySelector('.ag-llm-btn');
    var status = wrap.querySelector('.ag-llm-status');
    var out    = wrap.querySelector('.ag-llm-result');

    if (!shell || !input || !btn || !status || !out) return;

// Language: URL ?lang=tr wins; otherwise use <html lang="...">; default English.
function getQueryParam(name){
  try {
    var sp = new URLSearchParams(window.location.search || '');
    return sp.get(name);
  } catch(e){
    var m = (window.location.search || '').match(new RegExp('(?:^|[?&])' + name + '=([^&]*)'));
    return m ? decodeURIComponent(m[1].replace(/\+/g,' ')) : null;
  }
}
function detectLang(){
  var p = getQueryParam('lang');
  if (p) {
    p = String(p).toLowerCase();
    return (p.indexOf('tr') === 0) ? 'tr' : 'en';
  }
  var h = (document.documentElement && document.documentElement.getAttribute('lang')) || '';
  h = String(h).toLowerCase();
  return (h.indexOf('tr') === 0) ? 'tr' : 'en';
}
var I18N = {
  tr: {
    placeholder: 'Yazıda AI ile bul',
    btnAria: 'Gönder',
    labelQuestion: 'Soru',
    emptyQuery: 'Lütfen bir arama terimi yazın.',
    requestNotPossible: 'İstek yapılamadı.',
    loading: 'Yükleniyor…',
    unexpected: 'Beklenmeyen sonuç.',
    requestFailed: 'İstek tamamlanamadı. Lütfen tekrar deneyin.',
    networkError: 'Ağ hatası. Lütfen tekrar deneyin.'
  },
  en: {
    placeholder: 'Find with AI',
    btnAria: 'Send',
    labelQuestion: 'Question',
    emptyQuery: 'Please type a question or search query.',
    requestNotPossible: 'Request could not be made.',
    loading: 'Loading…',
    unexpected: 'Unexpected result.',
    requestFailed: 'Request failed. Please try again.',
    networkError: 'Network error. Please try again.'
  }
};
var lang = detectLang();
var T = I18N[lang] || I18N.en;

// Apply UI strings
input.setAttribute('placeholder', T.placeholder);
btn.setAttribute('aria-label', T.btnAria);

    shell.addEventListener('click', function(e){
      var t = e.target;
      if (t && t.closest && t.closest('.ag-llm-btn')) return;
      input && input.focus();
    });
    function setLoading(isLoading){
      wrap.classList.toggle('is-loading', !!isLoading);
      btn.disabled = !!isLoading;
      btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }
    function setStatus(text){
      status.textContent = text || '';
      wrap.classList.toggle('has-status', !!text);
    }
    function escapeHtml(s){
      return String(s || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
    }
    function capFirst(s){
      s = String(s || '').trim();
      if (!s) return s;
      return s.charAt(0).toUpperCase() + s.slice(1);
    }
    function isLikelyQuestion(q){
  var s = String(q || '').trim().toLowerCase();
  if (!s) return false;
  if (s.indexOf('?') !== -1) return true;

  if (lang === 'tr') {
    if (/^(ne|neden|nasıl|nasil|kim|kaç|kac|hangi|nerede|nereye|nerden|nereden|ne zaman)/.test(s)) return true;
    if (/(mı|mi|mu|mü)/.test(s)) return true;
    if (/(mı|mi|mu|mü)$/.test(s)) return true;
    return false;
  }

  // English (and everything else -> English UI)
  if (/^(what|why|how|who|whom|whose|where|when|which)/.test(s)) return true;
  if (/^(is|are|am|was|were|do|does|did|can|could|should|would|will|may|might|have|has|had)/.test(s)) return true;
  return false;
}
    function normalizeQuestionForDisplay(q){
      var s = capFirst(q);
      if (!s) return s;
      var hasQM = /\?\s*$/.test(s);
      if (!hasQM && isLikelyQuestion(q)) s = s.replace(/\s+$/,'') + '?';
      return s;
    }

    function formatAnswer(raw){
      var text = String(raw || '').trim();
      if (!text) return { html: '' };

      if (/\n|•|\-\s|\*\s/.test(text)) {
        text = text.replace(/\n{3,}/g, "\n\n");
        var lines = text.split(/\n/);
        var htmlParts = [];
        var list = [];

        function flushList(){
          if (list.length){
            htmlParts.push('<ul>' + list.map(function(li){
              return '<li>' + escapeHtml(li.replace(/^(\-|\*|•)\s*/,'').trim()) + '</li>';
            }).join('') + '</ul>');
            list = [];
          }
        }

        lines.forEach(function(line){
          var l = line.trim();
          if (!l) { flushList(); return; }
          if (/^(\-|\*|•)\s+/.test(l)) { list.push(l); return; }
          flushList();
          htmlParts.push('<p>' + escapeHtml(l) + '</p>');
        });

        flushList();
        return { html: htmlParts.join('') };
      }

      var sentences = text.split(/(?<=[\.\!\?])\s+/).filter(Boolean);
      var paras = [];
      for (var i=0; i<sentences.length; i+=2){
        var chunk = sentences.slice(i, i+2).join(' ');
        paras.push('<p>' + escapeHtml(chunk.trim()) + '</p>');
      }
      return { html: paras.join('') };
    }

    function setResult(question, answer){
      var qDisp = normalizeQuestionForDisplay(question);
      var formatted = formatAnswer(answer);
      out.innerHTML =
        '<div class="ag-llm-q"><strong>' + escapeHtml(qDisp) + '</strong></div>' +
        '<div class="ag-llm-a">' + (formatted.html || '') + '</div>';
      wrap.classList.toggle('has-result', true);
    }
    function clearResult(){
      out.innerHTML = '';
      wrap.classList.toggle('has-result', false);
    }

    function request(q){
      var config = window.summaraiFrontend || {};
      var postId = wrap.dataset ? parseInt(wrap.dataset.postId, 10) : 0;
      if (!config.restUrl || !config.nonce) {
        setResult(q, T.requestNotPossible);
        return;
      }
      if (!postId) {
        setResult(q, T.requestNotPossible);
        return;
      }

      setLoading(true);
      setStatus(T.loading);
      clearResult();
      input.value = '';

      fetch(config.restUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce
        },
        body: JSON.stringify({ prompt: q, post_id: postId }),
        credentials: 'same-origin'
      })
        .then(function(r){ return r.json(); })
        .then(function(json){
          setStatus('');
          if (!json) { setResult(q, T.unexpected); return; }
          if (json.ok) {
            setResult(q, json.answer || '');
            return;
          }
          setResult(q, json.error ? json.error : T.requestFailed);
        })
        .catch(function(){
          setStatus('');
          setResult(q, T.networkError);
        })
        .finally(function(){
          setLoading(false);
          setTimeout(function(){ input && input.focus(); }, 80);
        });
    }

    btn.addEventListener('click', function(){
      var q = (input.value || '').trim();
      if (!q) { setResult(T.labelQuestion, T.emptyQuery); return; }
      request(q);
    });

    input.addEventListener('keydown', function(e){
      if (e.key === 'Enter') btn.click();
    });
  }

  function initAll(){
    var nodes = document.querySelectorAll('.ag-llm-search');
    nodes.forEach(function(node){ initWidget(node); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
