<?php
require "vendor/autoload.php";
$p = App\Helpers\Db::tryConnection();
$r = new App\Services\LotacaoResolver($p);
// Carlos id 3 - Finados 2026-11-02 (nacional)
$cfg = $r->resolve(3, "2026-11-02");
echo "lotacao_id=".($cfg["id"]??"?")." uf=".($cfg["uf"]??"")." loc=".($cfg["localidade_id"]??"")."\n";
echo "feriados=".count($cfg["feriados"]??[])."\n";
foreach (($cfg["feriados"]??[]) as $f) {
  echo " - ".$f["escopo"]." ".$f["nome"]." ".$f["data"]."\n";
}
// Aniversario Parauapebas 2026-05-18
$cfg2 = $r->resolve(3, "2026-05-18");
echo "mun_feriados=".count($cfg2["feriados"]??[])."\n";
foreach (($cfg2["feriados"]??[]) as $f) {
  echo " - ".$f["escopo"]." ".$f["nome"]."\n";
}
// PA estadual 2026-08-15
$cfg3 = $r->resolve(3, "2026-08-15");
echo "est_feriados=".count($cfg3["feriados"]??[])."\n";
foreach (($cfg3["feriados"]??[]) as $f) {
  echo " - ".$f["escopo"]." ".$f["nome"]."\n";
}
