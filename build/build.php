<?php
/**
 * Gera os pacotes instaláveis:
 *   dist/pagou-opencart3-<versão>.ocmod.zip  (OpenCart 3.0.x: Extensões > Instalador)
 *   dist/pagou.ocmod.zip                     (OpenCart 4.x: o nome TEM de ser pagou.ocmod.zip)
 *
 * O SDK (sdk/src) é a fonte única: é copiado para dentro de cada pacote.
 * Uso: php build/build.php
 */
$root = dirname(__DIR__);
require $root . '/sdk/src/Client.php';
$version = \Pagou\OpenCart\Client::VERSION;

function rcopy($from, $to) {
	if (!is_dir($to)) {
		mkdir($to, 0775, true);
	}
	foreach (scandir($from) as $f) {
		if ($f === '.' || $f === '..') {
			continue;
		}
		is_dir("$from/$f") ? rcopy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
	}
}

function zipDir($dir, $zipFile, $prefixInZip = '') {
	if (is_file($zipFile)) {
		unlink($zipFile);
	}
	$zip = new ZipArchive();
	if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
		throw new RuntimeException("não abriu $zipFile");
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	$files = array();
	foreach ($it as $file) {
		$files[] = $file->getPathname();
	}
	sort($files);
	foreach ($files as $path) {
		$rel = str_replace('\\', '/', substr($path, strlen($dir) + 1));
		$zip->addFile($path, $prefixInZip . $rel);
	}
	$zip->close();
}

// Sincroniza o SDK nos dois pacotes
rcopy($root . '/sdk/src', $root . '/oc3/upload/system/library/pagou');
rcopy($root . '/sdk/src', $root . '/oc4/pagou/system/library/sdk');

// Versão no install.json do OC 4
$installJson = $root . '/oc4/pagou/install.json';
if (is_file($installJson)) {
	$meta = json_decode(file_get_contents($installJson), true);
	$meta['version'] = $version;
	file_put_contents($installJson, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

// Lint de tudo antes de empacotar
$bad = 0;
foreach (array($root . '/sdk', $root . '/oc3', $root . '/oc4') as $base) {
	if (!is_dir($base)) {
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		if (substr($file->getFilename(), -4) === '.php') {
			exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
			if ($code !== 0) {
				$bad++;
				echo implode("\n", $out), "\n";
			}
			$out = array();
		}
	}
}
if ($bad) {
	fwrite(STDERR, "$bad arquivo(s) com erro de sintaxe\n");
	exit(1);
}

@mkdir($root . '/dist', 0775, true);

// OC 3: o zip precisa da pasta upload/ na raiz
$oc3 = $root . "/dist/pagou-opencart3-$version.ocmod.zip";
zipDir($root . '/oc3/upload', $oc3, 'upload/');
echo "OK $oc3\n";

// OC 4: o nome do arquivo vira o código da extensão (pagou.ocmod.zip)
if (is_dir($root . '/oc4/pagou')) {
	$oc4 = $root . '/dist/pagou.ocmod.zip';
	zipDir($root . '/oc4/pagou', $oc4);
	// NÃO renomear: o instalador do OC 4 usa o nome do arquivo como código/pasta da extensão.
	@unlink($root . "/dist/pagou-opencart4-$version.ocmod.zip");
	echo "OK $oc4\n";
}
