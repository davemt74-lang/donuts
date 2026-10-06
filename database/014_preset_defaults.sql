INSERT OR IGNORE INTO preset_packs(pack_size_id,name,slug,description,active,image_path,sort_order)
SELECT id,'Classic Trio','classic-trio','Three classic Fudge Donuts, ready to gift or enjoy.',1,'/images/gift-box.png',10 FROM pack_sizes WHERE size=3;

INSERT OR IGNORE INTO preset_packs(pack_size_id,name,slug,description,active,image_path,sort_order)
SELECT id,'Favorites Six','favorites-six','A balanced six-pack of customer-favorite flavors.',1,'/images/gift-box.png',20 FROM pack_sizes WHERE size=6;

INSERT OR IGNORE INTO preset_packs(pack_size_id,name,slug,description,active,image_path,sort_order)
SELECT id,'Signature Dozen','signature-dozen','Our signature dozen with all four core flavors.',1,'/images/gift-box.png',30 FROM pack_sizes WHERE size=12;

INSERT OR REPLACE INTO preset_pack_items(preset_pack_id,flavor_id,quantity)
SELECT p.id,f.id,1 FROM preset_packs p JOIN flavors f ON f.slug IN ('smores','cookies-cream','mint-chocolate') WHERE p.slug='classic-trio';

INSERT OR REPLACE INTO preset_pack_items(preset_pack_id,flavor_id,quantity)
SELECT p.id,f.id,2 FROM preset_packs p JOIN flavors f ON f.slug IN ('smores','caramel-pretzel','cookies-cream') WHERE p.slug='favorites-six';

INSERT OR REPLACE INTO preset_pack_items(preset_pack_id,flavor_id,quantity)
SELECT p.id,f.id,3 FROM preset_packs p JOIN flavors f ON f.slug IN ('smores','caramel-pretzel','cookies-cream','mint-chocolate') WHERE p.slug='signature-dozen';
