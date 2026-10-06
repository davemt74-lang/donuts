<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
$base=rtrim((string)($_SERVER['REQUEST_SCHEME']??'https').'://'.($_SERVER['HTTP_HOST']??'example.com'),'/');
$paths=['/','/builder.php?size=3','/builder.php?size=6','/builder.php?size=12','/story.php','/faq.php','/account.php'];
echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach($paths as $p)echo '<url><loc>'.htmlspecialchars($base.$p,ENT_XML1).'</loc></url>';
echo '</urlset>';
