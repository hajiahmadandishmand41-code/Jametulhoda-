<?php $isEdit = isset($item['id']); $typeLabels=['article'=>'مقاله','news'=>'خبر','event'=>'رویداد','report'=>'گزارش']; $errors=$errors??[]; ?>
<section class="admin-welcome"><div><p class="admin-kicker">تحریریه</p><h2><?= $isEdit?'ویرایش محتوا':'محتوای جدید' ?></h2></div></section>
<?php if($errors): ?><div class="form-errors" role="alert"><ul><?php foreach($errors as $error): ?><li><?=e($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="admin-form" method="post" action="<?=e($action)?>" novalidate><?=csrf_field()?>
<label>نوع محتوا<select name="content_type" required <?= $isEdit?'disabled':'' ?>><?php foreach($typeLabels as $v=>$l): ?><option value="<?=e($v)?>" <?= (($item['content_type']??'news')===$v?'selected':'') ?>><?=e($l)?></option><?php endforeach; ?></select></label>
<?php if($isEdit): ?><input type="hidden" name="content_type" value="<?=e($item['content_type'])?>"><?php endif; ?>
<label>عنوان<input name="title" value="<?=e($item['title']??'')?>" maxlength="250" required></label>
<label>Slug امن<input name="slug" value="<?=e($item['slug']??'')?>" maxlength="190" pattern="[A-Za-z0-9\-آ-ی۰-۹]+" required></label>
<label>خلاصه<textarea name="summary" maxlength="500"><?=e($item['summary']??'')?></textarea></label>
<label>متن<textarea name="body" rows="12"><?=e($item['body']??'')?></textarea></label>
<label>موضوع<select name="topic_id"><option value="">بدون موضوع</option><?php foreach($topics as $topic): ?><option value="<?=e((string)$topic['id'])?>" <?= ((string)($item['topic_id']??'')===(string)$topic['id']?'selected':'') ?>><?=e($topic['title'])?></option><?php endforeach; ?></select></label>
<label>وضعیت<select name="status"><?php foreach(['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'بایگانی'] as $v=>$l): ?><option value="<?=e($v)?>" <?= (($item['status']??'draft')===$v?'selected':'') ?>><?=e($l)?></option><?php endforeach; ?></select></label>
<label>تاریخ انتشار (اختیاری)<input type="datetime-local" name="published_at" value="<?=e(isset($item['published_at'])&&$item['published_at']?str_replace(' ','T',substr($item['published_at'],0,16)):'')?>"></label>
<div><button class="admin-button" type="submit">ذخیره</button> <a href="<?=e(url('/admin/content'))?>">انصراف</a></div></form>
