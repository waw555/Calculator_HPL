<?php

/** Shared application chrome, visual system and display-currency controller. */
function app_version(): string
{
    $version = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));
    return preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : '0.0.0';
}

/** Return a user-facing currency mark while keeping ISO codes in stored data. */
function app_currency_symbol(string $code): string
{
    $code = strtoupper(trim($code));
    return ['RUR' => '₽', 'RUB' => '₽', 'EUR' => '€', 'USD' => '$'][$code] ?? $code;
}

function app_header_currency_rates(): array
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        try {
            require_once __DIR__ . '/db.php';
            if (isset($pdo) && $pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
            }
            require_once __DIR__ . '/admin_schema.php';
        } catch (Throwable $e) {
            error_log('Unable to initialise currencies: ' . $e->getMessage());
        }
    }
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO || !function_exists('ensure_currencies_table')) {
        return ['RUB' => ['code' => 'RUB', 'name' => 'Российский рубль', 'rate_to_rub' => 1.0]];
    }

    try {
        $pdo = $GLOBALS['pdo'];
        ensure_currencies_table($pdo);

        $stmt = $pdo->query("SELECT code, name, rate_to_rub FROM currencies WHERE is_active = 1 ORDER BY code = 'RUB' DESC, code ASC");
        $rows = $stmt ? $stmt->fetchAll() : [];
        $rates = [];
        foreach ($rows as $row) {
            $rates[strtoupper((string)$row['code'])] = [
                'code' => strtoupper((string)$row['code']),
                'name' => (string)$row['name'],
                'rate_to_rub' => (float)$row['rate_to_rub'],
            ];
        }
        $rates['RUB'] = $rates['RUB'] ?? ['code' => 'RUB', 'name' => 'Российский рубль', 'rate_to_rub' => 1.0];
        return $rates;
    } catch (Throwable $e) {
        error_log('Unable to load header currency rates: ' . $e->getMessage());
        return ['RUB' => ['code' => 'RUB', 'name' => 'Российский рубль', 'rate_to_rub' => 1.0]];
    }
}

function app_header_organization(): array
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO || !function_exists('ensure_organization_table')) {
        return [];
    }

    try {
        $pdo = $GLOBALS['pdo'];
        ensure_organization_table($pdo);
        return $pdo->query('SELECT short_name, full_name, logo_path FROM organization_settings WHERE id = 1')->fetch() ?: [];
    } catch (Throwable $e) {
        error_log('Unable to load header organization: ' . $e->getMessage());
        return [];
    }
}

function app_header_styles(): string
{
    return <<<'CSS'
<style>
:root{--app-header-height:76px;--app-bg:#f3f6fb;--app-surface:#fff;--app-text:#172033;--app-muted:#64748b;--app-line:#dfe7f1;--app-primary:#2563eb;--app-primary-dark:#1d4ed8;--app-radius:16px;--app-shadow:0 12px 35px rgba(15,23,42,.08);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
*{box-sizing:border-box}html{background:var(--app-bg)}body{padding-top:var(--app-header-height);color:var(--app-text);-webkit-font-smoothing:antialiased}
.app-header{position:fixed;inset:0 0 auto;z-index:10000;min-height:var(--app-header-height);background:rgba(15,23,42,.97);color:#e5eefb;box-shadow:0 8px 28px rgba(15,23,42,.2);backdrop-filter:blur(14px)}
.app-header__inner{max-width:1440px;min-height:var(--app-header-height);margin:auto;padding:10px 24px;display:flex;align-items:center;gap:10px;white-space:nowrap}
.app-header__brand{display:flex;align-items:center;gap:11px;min-width:230px;text-decoration:none}.app-header__logo{width:56px;height:48px;border:1px solid rgba(255,255,255,.18);border-radius:12px;display:grid;place-items:center;overflow:hidden;background:linear-gradient(145deg,#fff,#eef2f7);box-shadow:0 7px 18px rgba(0,0,0,.24);font-size:22px}.app-header__logo img{display:block;width:100%;height:100%;object-fit:contain;padding:6px;filter:drop-shadow(0 1px 1px rgba(15,23,42,.1))}.app-header__title{display:flex;flex-direction:column;gap:3px}.app-header__title-row{display:flex;align-items:center;gap:7px}.app-header__name{color:#fff;font-size:19px;font-weight:850;letter-spacing:-.04em}.app-header__subtitle{color:#94a3b8;font-size:11px;font-weight:650}
.app-header__pill,.app-header__rates,.app-header__currency,.app-header__button,.app-header__user{min-height:34px;border:1px solid rgba(148,163,184,.2);background:rgba(30,41,59,.85);border-radius:10px;display:inline-flex;align-items:center;gap:7px;padding:0 10px;color:#cbd5e1;font:700 12px/1 inherit;text-decoration:none}
.app-header__pill{min-height:22px;padding:0 8px;color:#bfdbfe;background:#173256}.app-header__spacer{flex:1}.app-header__rates strong{color:#fff}.app-header__refresh{width:28px;height:28px;padding:0;justify-content:center;border:0;background:transparent;color:#94a3b8;border-radius:7px;cursor:pointer;font-size:16px}.app-header__refresh:hover{color:#fff;background:#334155}.app-header__refresh:disabled{cursor:wait;opacity:.6}.app-header__refresh--loading{animation:app-header-spin .8s linear infinite}@keyframes app-header-spin{to{transform:rotate(360deg)}}.app-header__currency{padding:3px}.app-header__currency-label{padding-left:7px;color:#94a3b8}.app-header__currency-option{border:0;background:transparent;color:#cbd5e1;padding:7px 8px;border-radius:7px;font:800 11px inherit;cursor:pointer}.app-header__currency-option:hover{background:#334155}.app-header__currency-option--active{color:#fff;background:#2563eb!important}.app-header__button{cursor:pointer}.app-header__button:hover{background:#334155}.app-header__user{color:#fff}.app-header__mode,.app-header__logout{color:#94a3b8;text-decoration:none;font-size:18px;display:inline-flex;align-items:center;justify-content:center}.app-header__mode:hover,.app-header__logout:hover{color:#fff}
/* A common finish for legacy pages; local layouts stay intact. */
body:not(.login-page){background-color:var(--app-bg)}main.container,.container{width:min(100% - 32px,1280px)}.panel,.card{border-color:var(--app-line)!important;border-radius:var(--app-radius)!important;box-shadow:var(--app-shadow)!important}.card{transition:transform .18s ease,box-shadow .18s ease}.card:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(15,23,42,.12)!important}button,.btn,.card a{transition:background .16s ease,transform .16s ease}button:active,.btn:active{transform:translateY(1px)}input,select,textarea{border-radius:9px!important;border-color:#cbd5e1!important;font:inherit}input:focus,select:focus,textarea:focus{outline:3px solid rgba(37,99,235,.14);border-color:#60a5fa!important}table{border-radius:12px;overflow:hidden}th{color:#475569;font-size:12px;letter-spacing:.02em}a:focus-visible,button:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
@media(max-width:1100px){:root{--app-header-height:126px}.app-header__inner{flex-wrap:wrap;align-content:center}.app-header__brand{flex:1}.app-header__spacer{display:none}.app-header__rates{order:3}.app-header__currency{order:4}}
@media(max-width:680px){:root{--app-header-height:174px}.app-header__inner{padding:8px 12px;gap:7px}.app-header__brand{flex-basis:100%}.app-header__button span{display:none}.app-header__rates{font-size:11px}.app-header__user{margin-left:auto}main.container,.container{width:min(100% - 20px,1280px);padding-left:0!important;padding-right:0!important}}
@media print{body{padding-top:0!important}.app-header{display:none!important}.panel,.card{box-shadow:none!important}}
</style>
CSS;
}

function render_app_header(string $section = 'Калькулятор'): void
{
    $role = $_SESSION['role'] ?? 'user';
    $username = $_SESSION['username'] ?? ($role === 'admin' ? 'admin' : 'user');
    $rates = app_header_currency_rates();
    $organization = app_header_organization();
    $logoPath = trim((string)($organization['logo_path'] ?? ''));
    $organizationName = trim((string)(($organization['short_name'] ?? '') ?: ($organization['full_name'] ?? '') ?: 'ООО «ТД Декотек»'));
    $eur = isset($rates['EUR']) ? '€ ' . number_format($rates['EUR']['rate_to_rub'], 2, ',', ' ') : '€ —';
    $usd = isset($rates['USD']) ? '$ ' . number_format($rates['USD']['rate_to_rub'], 2, ',', ' ') : '$ —';
    $ratesJson = json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $isAdminPage = str_starts_with(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), 'admin');
    ?>
<header class="app-header">
  <div class="app-header__inner">
    <a class="app-header__brand" href="<?php echo $isAdminPage ? 'admin.php' : 'calculator.php'; ?>" aria-label="На главную"><span class="app-header__logo"><?php if ($logoPath !== ''): ?><img src="<?php echo e($logoPath); ?>" alt="Логотип <?php echo e($organizationName); ?>"><?php else: ?><span aria-hidden="true">🧮</span><?php endif; ?></span><span class="app-header__title"><span class="app-header__title-row"><span class="app-header__name"><?php echo e($section); ?></span><span class="app-header__pill" title="Версия приложения">v<?php echo e(app_version()); ?></span></span><span class="app-header__subtitle"><?php echo e($organizationName); ?></span></span></a>
    <span class="app-header__spacer"></span>
    <span class="app-header__rates" title="Курсы ЦБ РФ. Обновляются при входе или вручную"><span>ЦБ РФ</span><strong><?php echo e($eur); ?> ₽</strong><strong><?php echo e($usd); ?> ₽</strong><button class="app-header__refresh" type="button" data-refresh-currency title="Обновить курсы валют" aria-label="Обновить курсы валют">↻</button></span>
    <span class="app-header__currency" role="group" aria-label="Валюта отображения"><span class="app-header__currency-label">Валюта</span><?php foreach ($rates as $code => $rate): ?><button class="app-header__currency-option" type="button" data-app-currency="<?php echo e($code); ?>" title="<?php echo e($rate['name']); ?>"><?php echo e(app_currency_symbol($code)); ?></button><?php endforeach; ?></span>
    <button class="app-header__button" type="button" onclick="window.print()" title="Печать страницы">▣ <span>Печать</span></button>
    <span class="app-header__user">♙ <?php echo e($username . ($role === 'admin' ? ' · Админ' : '')); ?><?php if ($role === 'admin'): ?><a class="app-header__mode" href="<?php echo $isAdminPage ? 'calculator.php' : 'admin.php'; ?>" title="<?php echo $isAdminPage ? 'Перейти в режим пользователя' : 'Открыть панель администратора'; ?>" aria-label="<?php echo $isAdminPage ? 'Перейти в режим пользователя' : 'Открыть панель администратора'; ?>"><?php echo $isAdminPage ? '👤' : '⚙'; ?></a><?php endif; ?><a class="app-header__logout" href="logout.php" title="Выйти" aria-label="Выйти">↪</a></span>
  </div>
</header>
<script>
(() => {
  const rates = <?php echo $ratesJson ?: '{}'; ?>;
  const refreshButton = document.querySelector('[data-refresh-currency]');
  const refreshRates = async (automatic = false) => {
    if (!automatic) {
      refreshButton.disabled = true;
      refreshButton.classList.add('app-header__refresh--loading');
    }
    try {
      const response = await fetch(`includes/currency_refresh.php${automatic ? '?automatic=1' : ''}`, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Не удалось обновить курсы валют.');
      if (!automatic || result.refreshed) window.location.reload();
    } catch (error) {
      if (!automatic) alert(error.message || 'Не удалось обновить курсы валют.');
    } finally {
      if (!automatic) {
        refreshButton.disabled = false;
        refreshButton.classList.remove('app-header__refresh--loading');
      }
    }
  };
  refreshButton?.addEventListener('click', () => refreshRates(false));
  const supported = Object.keys(rates);
  const saved = localStorage.getItem('stcalc.currency');
  let selected = supported.includes(saved) ? saved : 'RUB';
  const symbols = {RUB:'₽', EUR:'€', USD:'$'};
  const rate = code => Number(rates[code]?.rate_to_rub) || 1;
  const convert = (value, from = 'RUB', to = selected) => Number(value) * rate(from) / rate(to);
  const format = (value, code = selected) => new Intl.NumberFormat('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(value) || 0) + ' ' + (symbols[code] || code);
  const api = window.AppCurrency = {rates, get code(){return selected}, convert, format, fromRub:(v,to=selected)=>convert(v,'RUB',to)};
  const originalText = new WeakMap();
  const renderTagged = root => {
    const nodes = root.matches?.('[data-currency-value]') ? [root] : root.querySelectorAll?.('[data-currency-value]') || [];
    nodes.forEach(el => { const value = Number(el.dataset.currencyValue); if (Number.isFinite(value)) el.textContent = format(convert(value, el.dataset.currencyCode || 'RUB'), selected); });
  };
  const renderLegacyMoney = () => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const parent = node.parentElement;
        if (!parent || parent.closest('.app-header,script,style,textarea,select,option,[data-currency-value]')) return NodeFilter.FILTER_REJECT;
        return /(?:\d[\d\s.,]*\s)(?:RUB|EUR|USD|₽|€|\$)/u.test(originalText.get(node) || node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      }
    });
    const nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(node => {
      const source = originalText.get(node) || node.nodeValue; originalText.set(node, source);
      node.nodeValue = source.replace(/(\d[\d\s]*(?:[.,]\d+)?)\s*(RUB|EUR|USD|₽|€|\$)/gu, (all, raw, mark) => {
        const from = ({'₽':'RUB','€':'EUR','$':'USD'})[mark] || mark;
        if (!rates[from]) return all;
        const value = Number(raw.replace(/\s/g, '').replace(',', '.'));
        return Number.isFinite(value) ? format(convert(value, from, selected), selected) : all;
      });
    });
  };
  function select(code, announce = true) {
    if (!supported.includes(code)) return;
    selected = code; localStorage.setItem('stcalc.currency', code);
    document.documentElement.dataset.currency = code;
    document.querySelectorAll('[data-app-currency]').forEach(btn => { const active = btn.dataset.appCurrency === code; btn.classList.toggle('app-header__currency-option--active', active); btn.setAttribute('aria-pressed', String(active)); });
    renderTagged(document.documentElement);
    renderLegacyMoney();
    if (announce) window.dispatchEvent(new CustomEvent('appcurrencychange', {detail:{code, rates, convert, format}}));
  }
  document.querySelectorAll('[data-app-currency]').forEach(btn => btn.addEventListener('click', () => select(btn.dataset.appCurrency)));
  new MutationObserver(items => items.forEach(item => item.addedNodes.forEach(node => { if (node.nodeType === 1) renderTagged(node); }))).observe(document.body, {childList:true, subtree:true});
  api.set = select; select(selected, false);
  window.addEventListener('DOMContentLoaded', () => window.dispatchEvent(new CustomEvent('appcurrencychange', {detail:{code:selected, rates, convert, format}})), {once:true});
})();
</script>
<?php
}
