<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,CatalogRepository,Database,RecipeService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$catalog=new CatalogRepository($db);$svc=new RecipeService($db);$audit=new AdminAuditService($db);$error='';$notice='';
$flavorId=(int)($_GET['flavor_id']??$_POST['flavor_id']??0);
$flavors=$catalog->flavors(false);
if($flavorId<1 && $flavors)$flavorId=(int)$flavors[0]['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            $names=(array)($_POST['ingredient_name']??[]);$qtys=(array)($_POST['quantity_per_donut']??[]);$units=(array)($_POST['quantity_unit']??[]);
            $components=[];$count=max(count($names),count($qtys),count($units));
            for($i=0;$i<$count;$i++)$components[]=[
                'ingredient_name'=>(string)($names[$i]??''),
                'quantity_per_donut'=>(string)($qtys[$i]??''),
                'quantity_unit'=>(string)($units[$i]??''),
            ];
            $id=$svc->createVersion($flavorId,$components,(string)($_POST['notes']??''),(int)$_SESSION['admin_id'],!empty($_POST['activate']));
            $audit->record((int)$_SESSION['admin_id'],'recipe_version_created','recipe',$id,'Flavor recipe version created.',[],['flavor_id'=>$flavorId,'activate'=>!empty($_POST['activate'])]);
            $notice='Recipe version created'.(!empty($_POST['activate'])?' and activated.':'.');
        }elseif($action==='activate'){
            $recipeId=(int)($_POST['recipe_id']??0);$svc->activate($recipeId);
            $audit->record((int)$_SESSION['admin_id'],'recipe_version_activated','recipe',$recipeId,'Flavor recipe version activated.');
            $notice='Recipe version activated.';
        }else throw new InvalidArgumentException('Unsupported recipe action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$recipes=$flavorId?$svc->recipesForFlavor($flavorId):[];$active=$flavorId?$svc->activeForFlavor($flavorId):null;$summary=$svc->summary();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recipes & BOM · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a class="active" href="/admin-recipes.php">Recipes</a><a href="/admin-food-compliance.php">Food Compliance</a><a href="/admin-ingredient-lots.php">Ingredients</a><a href="/admin-batches.php">Batches</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Production formulas</p><h1>Recipes & Bill of Materials</h1><p class="admin-welcome">Version flavor formulas and calculate exact supplier-lot consumption for each production batch.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Active flavors</span><strong><?=$summary['active_flavors']?></strong></article><article class="kpi-card"><span>With active recipe</span><strong><?=$summary['flavors_with_recipe']?></strong></article><article class="kpi-card <?=$summary['missing_recipes']?'kpi-alert':''?>"><span>Missing recipe</span><strong><?=$summary['missing_recipes']?></strong></article><article class="kpi-card"><span>Controlled batches</span><strong><?=$summary['controlled_batches']?></strong></article></section>

<section class="dashboard-panel"><form method="get" class="inline-admin"><label>Flavor<select name="flavor_id" onchange="this.form.submit()"><?php foreach($flavors as $f):?><option value="<?=(int)$f['id']?>" <?=(int)$f['id']===$flavorId?'selected':''?>><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></label><noscript><button class="button secondary">Load flavor</button></noscript></form></section>

<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Version history</p><h2><?=htmlspecialchars($active['flavor_name']??'Flavor')?> formulas</h2></div></div>
<?php if(!$recipes):?><div class="dashboard-empty"><strong>No recipe defined yet.</strong><span>Create the first production formula to enable BOM-controlled ingredient allocation.</span></div><?php endif;?>
<div class="recipe-version-list"><?php foreach($recipes as $r):$full=$svc->recipe((int)$r['id']);?><article class="recipe-version-card <?=$r['status']==='active'?'recipe-active':''?>"><div class="panel-head"><div><strong>Version <?=(int)$r['version']?></strong><small><?=htmlspecialchars(ucfirst($r['status']))?> · <?=(int)$r['batch_count']?> production batch<?=((int)$r['batch_count']===1?'':'es')?></small></div><?php if($r['status']!=='active'):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="flavor_id" value="<?=$flavorId?>"><input type="hidden" name="recipe_id" value="<?=(int)$r['id']?>"><button class="button secondary">Activate</button></form><?php endif;?></div><table class="dashboard-table compact-table"><thead><tr><th>Ingredient</th><th>Per donut</th></tr></thead><tbody><?php foreach($full['components'] as $c):?><tr><td><?=htmlspecialchars($c['ingredient_name'])?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$c['quantity_per_donut'],6,'.',''),'0'),'.').' '.$c['quantity_unit'])?></td></tr><?php endforeach;?></tbody></table><?php if($r['notes']):?><p class="muted"><?=nl2br(htmlspecialchars($r['notes']))?></p><?php endif;?></article><?php endforeach;?></div></div>

<div class="dashboard-panel"><p class="eyebrow">New immutable version</p><h2>Create recipe</h2><p class="muted">Quantities are per finished donut. Activating this version retires the current active recipe but never changes historical batch snapshots.</p>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create"><input type="hidden" name="flavor_id" value="<?=$flavorId?>">
<div class="recipe-component-editor"><?php for($i=0;$i<8;$i++):?><div class="recipe-component-row"><label>Ingredient<input name="ingredient_name[]" maxlength="190" placeholder="<?=$i===0?'Chocolate':''?>"></label><label>Qty / donut<input type="number" name="quantity_per_donut[]" step="0.000001" min="0.000001"></label><label>Unit<input name="quantity_unit[]" maxlength="32" placeholder="oz"></label></div><?php endfor;?></div>
<label>Version notes<textarea name="notes" maxlength="4000" placeholder="Formula change, supplier specification, process note…"></textarea></label><label class="check"><input type="checkbox" name="activate" value="1" checked> Activate immediately</label><button class="button">Create recipe version</button></form></div>
</section></main></body></html>