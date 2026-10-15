<?php
declare(strict_types=1);
require __DIR__.'/../private/src/DraftDescription.php';
$draft=['description'=>'old','brand'=>'Hyundai','photo_file_ids'=>['a','b'],'downloaded_photo_paths'=>['a'=>'path'],'price_rub'=>1075000];
$edited=DraftDescription::replace($draft,'  Панорама, кожаный салон.  ');
if($edited['description']!=='Панорама, кожаный салон.')throw new RuntimeException('Description not updated');
unset($draft['description'],$edited['description']);
if($draft!==$edited)throw new RuntimeException('Editing changed other fields or photos');
foreach(['',str_repeat('я',1201)]as$invalid){try{DraftDescription::replace($draft,$invalid);throw new LogicException('Invalid text accepted');}catch(InvalidArgumentException $e){}}
echo "Draft description passed: text changed, all other fields/photos preserved, invalid edits rejected.\n";
