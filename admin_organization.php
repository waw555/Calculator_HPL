<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';
ensure_organization_table($pdo);
$errors = [];
$saved = false;
$org = $pdo->query('SELECT * FROM organization_settings WHERE id = 1')->fetch() ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['full_name','short_name','address','city','region','postal_code','phone','website','email','note','inn','ogrn','bik','bank_name'];
    $data = [];
    foreach ($fields as $field) {
        $value = trim($_POST[$field] ?? '');
        $data[$field] = $value === '' ? null : $value;
    }
    $data['logo_path'] = upload_image('logo', 'organization', $errors, $org['logo_path'] ?? null);
    if (!$errors) {
        $data['id'] = 1;
        $stmt = $pdo->prepare('UPDATE organization_settings SET full_name=:full_name, short_name=:short_name, address=:address, city=:city, region=:region, postal_code=:postal_code, phone=:phone, website=:website, email=:email, note=:note, logo_path=:logo_path, inn=:inn, ogrn=:ogrn, bik=:bik, bank_name=:bank_name WHERE id=:id');
        $stmt->execute($data);
        $saved = true;
        $org = $pdo->query('SELECT * FROM organization_settings WHERE id = 1')->fetch() ?: [];
    }
}
?>
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Организация</title><style>
body{font-family:'Inter',Arial,sans-serif;background:#f5f7fb;margin:0;color:#1f2937}.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:radial-gradient(circle at 10% 10%,rgba(59,130,246,.35),transparent 32%),linear-gradient(120deg,#0f172a,#1e3a8a 58%,#0f766e);color:#fff;padding:22px 36px 30px;box-shadow:0 18px 45px rgba(15,23,42,.18)}.header a{color:#dbeafe;font-weight:700;text-decoration:none;margin-right:16px}.container{max-width:1180px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;margin-bottom:24px;box-shadow:0 8px 24px rgba(15,23,42,.06)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}label{display:block;font-weight:600;margin-bottom:6px}input,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}textarea{min-height:90px}button{border:0;border-radius:8px;padding:10px 14px;background:#2563eb;color:#fff;cursor:pointer}.errors{background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px}.success{background:#dcfce7;color:#166534;padding:12px;border-radius:8px}.preview{max-width:180px;max-height:110px;border:1px solid #e5e7eb;border-radius:10px;object-fit:contain;background:#fff}.hint{color:#64748b;font-size:13px}
</style>
<?php echo app_header_styles(); ?></head><body><?php render_app_header(); ?>
<main class="container"><section class="panel"><h2>Реквизиты организации</h2><?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?><?php if ($saved): ?><div class="success">Данные организации сохранены.</div><?php endif; ?><form method="post" enctype="multipart/form-data"><div class="grid"><?php foreach ([['full_name','Полное название организации'],['short_name','Краткое название организации'],['address','Адрес'],['city','Город'],['region','Область'],['postal_code','Индекс'],['phone','Телефон'],['website','Сайт'],['email','Электронная почта'],['inn','ИНН'],['ogrn','ОГРН'],['bik','БИК'],['bank_name','Название банка']] as $item): ?><div><label for="<?php echo e($item[0]); ?>"><?php echo e($item[1]); ?></label><input id="<?php echo e($item[0]); ?>" name="<?php echo e($item[0]); ?>" value="<?php echo e((string)($org[$item[0]] ?? '')); ?>"></div><?php endforeach; ?><div><label for="logo">Логотип</label><input id="logo" type="file" name="logo" accept=".jpg,.jpeg,.png,.tif,.tiff,.webp,image/jpeg,image/png,image/tiff,image/webp"><div class="hint">Загрузите файл логотипа до 100 мб.</div><?php if (!empty($org['logo_path'])): ?><p><img class="preview" id="logo_preview" src="<?php echo e($org['logo_path']); ?>" alt="Превью логотипа"></p><?php else: ?><p><img class="preview" id="logo_preview" src="" alt="Превью логотипа" style="display:none"></p><?php endif; ?></div></div><p><label for="note">Примечание</label><textarea id="note" name="note"><?php echo e((string)($org['note'] ?? '')); ?></textarea></p><button type="submit">Сохранить</button></form></section></main><script>document.getElementById('logo').addEventListener('change',e=>{const img=document.getElementById('logo_preview');const f=e.target.files[0];if(!f)return;img.src=URL.createObjectURL(f);img.style.display='block';});</script></body></html>
