<?php
require "vendor/autoload.php";
$p = App\Helpers\Db::tryConnection();
echo "JORNADAS\n";
foreach ($p->query("SELECT id,codigo,nome FROM rh_jornadas") as $r) echo "{$r['id']} {$r['codigo']} {$r['nome']}\n";
echo "ESCALAS\n";
foreach ($p->query("SELECT id,codigo,nome,tipo FROM rh_escalas") as $r) echo "{$r['id']} {$r['codigo']} {$r['tipo']}\n";
echo "LOTACOES\n";
foreach ($p->query("SELECT id,codigo,jornada_id,escala_id,localidade_id FROM rh_lotacoes") as $r) echo "{$r['id']} {$r['codigo']} j={$r['jornada_id']} e={$r['escala_id']} loc={$r['localidade_id']}\n";
echo "COLABS=". $p->query("SELECT COUNT(*) FROM rh_colaboradores")->fetchColumn() ."\n";
echo "EMPRESAS\n";
foreach ($p->query("SELECT id,nome FROM rh_empresas") as $r) echo "{$r['id']} {$r['nome']}\n";
