<?php

function app_header_currency_rates(): array
{
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO || !function_exists('ensure_currencies_table')) {
        return [];
    }

    try {
        ensure_currencies_table($GLOBALS['pdo']);
        $stmt = $GLOBALS['pdo']->query("SELECT code, rate_to_rub FROM currencies WHERE is_active = 1 AND code IN ('EUR','USD') ORDER BY FIELD(code, 'EUR', 'USD')");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function app_header_styles(): string
{
    return <<<'CSS'
<style>
:root { --app-header-height: 72px; }
body { padding-top: var(--app-header-height); }
.app-header { position: fixed; top: 0; left: 0; right: 0; z-index: 10000; height: var(--app-header-height); background: #0f172a; color: #e5eefb; box-shadow: 0 8px 24px rgba(15,23,42,.22); }
.app-header__inner { max-width: 1215px; height: 100%; margin: 0 auto; padding: 0 22px; display: flex; align-items: center; gap: 12px; box-sizing: border-box; white-space: nowrap; }
.app-header__brand { text-decoration: none; display: flex; align-items: center; gap: 12px; min-width: 265px; }
.app-header__logo { width: 48px; height: 48px; border-radius: 12px; display: grid; place-items: center; background: linear-gradient(135deg, #3b82f6, #22d3ee); box-shadow: 0 10px 22px rgba(59,130,246,.35); font-size: 24px; }
.app-header__title { display: flex; flex-direction: column; gap: 4px; }
.app-header__title-row { display: flex; align-items: center; gap: 8px; }
.app-header__name { color: #f8fafc; font-size: 20px; line-height: 1; font-weight: 800; letter-spacing: -.04em; }
.app-header__subtitle { color: #94a3b8; font-size: 12px; font-weight: 700; }
.app-header__pill, .app-header__rates, .app-header__currency, .app-header__button, .app-header__user { min-height: 31px; border: 1px solid rgba(148,163,184,.22); background: rgba(30,41,59,.82); border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; padding: 0 11px; color: #cbd5e1; font-size: 12px; font-weight: 800; text-decoration: none; box-sizing: border-box; }
.app-header__pill { min-height: 23px; padding: 0 9px; color: #bfdbfe; background: #173256; }
.app-header__db { color: #34d399; border-color: rgba(16,185,129,.35); background: rgba(6,78,59,.34); }
.app-header__spacer { flex: 1 1 auto; min-width: 12px; }
.app-header__rates strong { color: #f8fafc; }
.app-header__refresh { color: #38bdf8; font-size: 15px; }
.app-header__currency { padding: 4px 7px 4px 10px; }
.app-header__currency-label { color: #94a3b8; margin-right: 2px; }
.app-header__currency-option { color: #cbd5e1; padding: 5px 8px; border-radius: 6px; line-height: 1; }
.app-header__currency-option--active { color: #fff; background: #2563eb; }
.app-header__button { cursor: pointer; font-family: inherit; }
.app-header__button svg, .app-header__user svg { width: 16px; height: 16px; }
.app-header__user { color: #f8fafc; }
.app-header__logout { color: #94a3b8; display: inline-flex; margin-left: 4px; }
@media (max-width: 980px) { :root { --app-header-height: 116px; } .app-header__inner { flex-wrap: wrap; align-content: center; } .app-header__brand { min-width: auto; flex: 1 1 100%; } .app-header__spacer { display:none; } }
@media print { body { padding-top: 0; } .app-header { display: none !important; } }
</style>
CSS;
}

function render_app_header(string $section = 'HPL / Компакт-плиты'): void
{
    $role = $_SESSION['role'] ?? 'user';
    $username = $_SESSION['username'] ?? ($role === 'admin' ? 'admin' : 'user');
    $rates = app_header_currency_rates();
    $eur = isset($rates['EUR']) ? '€ 1 = ' . number_format((float)$rates['EUR'], 2, '.', '') . ' ₽' : '€ —';
    $usd = isset($rates['USD']) ? '$ 1 = ' . number_format((float)$rates['USD'], 2, '.', '') . ' ₽' : '$ —';
    ?>
<header class="app-header">
  <div class="app-header__inner">
    <a class="app-header__brand" href="calculator.php" aria-label="STCalc">
      <span class="app-header__logo">🧮</span>
      <span class="app-header__title"><span class="app-header__title-row"><span class="app-header__name">STCalc</span><span class="app-header__pill"><?php echo e($section); ?></span></span><span class="app-header__subtitle">ООО "ТД Декотек"</span></span>
    </a>
    <span class="app-header__pill app-header__db">✓ Облако БД активна</span>
    <span class="app-header__spacer"></span>
    <span class="app-header__rates"><span>Курсы ЦБ:</span><strong><?php echo e($eur); ?></strong><strong><?php echo e($usd); ?></strong><span class="app-header__refresh">↻</span></span>
    <span class="app-header__currency"><span class="app-header__currency-label">Валюта:</span><span class="app-header__currency-option app-header__currency-option--active">RUB</span><span class="app-header__currency-option">EUR</span><span class="app-header__currency-option">USD</span></span>
    <button class="app-header__button" type="button" onclick="window.print()">▣ Печать</button>
    <span class="app-header__user">♙ <?php echo e($username . ($role === 'admin' ? ' (Админ)' : '')); ?><a class="app-header__logout" href="logout.php">↪</a></span>
  </div>
</header>
<?php
}
