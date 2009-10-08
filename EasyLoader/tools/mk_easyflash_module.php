<?php

if($argc != 3){
	fail("usage: ".$argv[0]." <file> <package>\n");
}
$MODE = $argv[1];

if($MODE == 'build/easyloader_nrm.prg'){
	$MODE = array(0, 0, false, false);
}else if($MODE == 'build/easyloader_ocm.prg'){
	$MODE = array(1, 0, true, true);
}else{
	fail("unknown argument for <file>: \"".$MODE."\"\n");
}

// $MODE = array(bank loader, bank fs, loader is high, is shadow mode)

$f = file_get_contents('php://stdin');

if(ord($f[0]) != 0x00 || ord($f[1]) != ($MODE[2] ? 0xa0 : 0x80)){
	fail('wrong start address');
}

$f = substr($f, 2);

$size = strlen($f);


/* collect files! */
$BASE_PATH = dirname($argv[2]).'/';
$config = array(
	'strip_extension' => 0,
	'show_free' => 1,
	'show_memmap' => 0,
	'memmap_colwidth' => 40,
);

$more_crt8 = array();
$more_crt8u = array();
$more_crt16 = array();
$more_crt16u = array();
$more_files = array();
$more_m2i = array();
$mod256k = array();
$more_mmc16 = array();
$more_mmc8u = array();
$is_hidden = array();

foreach(split("[\r\n]+", file_get_contents($argv[2])) AS $ln){
	if(trim($ln) == '' || substr(trim($ln), 0, 1) == '#'){
		// empty line or comment -> skip line
		continue;
	}
	if(!preg_match('!^(\.)?(.*):(.*?)(=(.*))?$!', $ln, $match)){
		fail('bad package syntax: '.$ln);
	}
	$hidden = isset($match[1]) && $match[1] == '.';
	$mode = $match[2];
	$file = $match[3];
	$name = isset($match[5]) ? $match[5] : '';

	if($mode == 'cfg'){
		$config[$file] = trim($name);
	}else{
		if($name == ''){
			$name = basename($file);
			if($config['strip_extension']){
				$name = substr($name, 0, -4);
			}else{
				$name = $name;
			}
		}
		if($file[0] != '/'){
			// file is relative -> prepend base path
			$file = $BASE_PATH.$file;
		}
		$is_hidden[$file] = $hidden ? 0x80 : 0x00;
		switch($mode){
		case 'p':
		case 'prg':
			$more_files[$file] = $name;
			break;
		case '8':
		case '8k':
			$more_crt8[$file] = $name;
			break;
		case '16':
		case '16k':
			$more_crt16[$file] = $name;
			break;
		case 'm8':
		case 'm8k':
			$more_crt8u[$file] = $name;
			break;
		case 'm16':
		case 'm16k':
			$more_crt16u[$file] = $name;
			break;
		case 'ocean':
			$mod256k = array($name, $file);
			break;
		case 'mmc16':
		case 'mmc16k':
			$more_mmc16[$file] = $name;
			break;
		case 'mmcm8':
		case 'mmcm8k':
			$more_mmc8u[$file] = $name;
			break;
//		case 'm2i':
//			$more_m2i[$name] = $file;
//			break;
		}
	}
}

/*
	read all prg's and 8k/m8k crt's
*/

$dir_crt8 = array();
$dir_crt8u = array();
$dir_prg8 = array();
$dir_prg = array();

foreach($more_crt8 AS $file => $name){
	$dir_crt8[] = array(
		'dir' => array(
			$name,
			-1,
			0x10 | $is_hidden[$file],
			8*1024,
			0,
		),
		'data' => array(substr(file_get_contents($file), -8*1024)),
	);
}

foreach($more_crt8u AS $file => $name){
	$dir_crt8u[] = array(
		'dir' => array(
			$name,
			-1,
			0x13 | $is_hidden[$file],
			8*1024,
			0,
		),
		'data' => array(substr(file_get_contents($file), -8*1024)),
	);
}

foreach($more_files AS $file => $name){
	$d = file_get_contents($file);
	if(substr($d, 0, 8) == 'C64File'.chr(0)){
		// found a P00 file -> chop header
		$d = substr($d, 26);
	}
	if(strlen($d) <= 8*1024){
		$dir_prg8[] = array(
			'dir' => array(
				$name,
				-1,
				0x01 | $is_hidden[$file],
				strlen($d),
				0,
			),
			'data' => $d,
		);
	}else{
		$dir_prg[] = array(
			'dir' => array(
				$name,
				-1,
				0x01 | $is_hidden[$file],
				strlen($d),
				0,
			),
			'data' => $d,
		);
	}
}

foreach($more_mmc8u AS $file => $name){
	$mmc = file_get_contents($file);
	$mmc_banks = ceil(strlen($mmc) / 0x2000);

	$data = array();
	for($i=0; $i<$mmc_banks; $i++){
		$data[] = substr($mmc, $i*0x2000, 0x2000);
	}
	
	$dir_crt8u[] = array(
		'dir' => array(
			$name,
			-1,
			0x13 | $is_hidden[$file],
			$mmc_banks * 0x2000,
			0
		),
		'data' => $data,
	);
}


usort($dir_prg8, 'sort_dirs_prg');


/*
  CartridgeHeader

  Byte CartrigeMagic[16];   $00 - $0F 
  Long HeaderSize;          $10 - $13 
  Word Version;             $14 - $15 
  Word HardwareType;        $16 - $17 
  Byte ExROM_Line           $18 
  Byte Game_Line            $19 
  Byte Unused[6];           $1A - $1F 
  Byte CartridgeName[32];   $20 - $3F 
*/
/*
  ChipPacket

  Byte ChipMagic[4];   0x00 - 0x03 
  Long PacketLength;   0x04 - 0x07 
  Word ChipType;       0x08 - 0x09 
  Word Bank;           0x0A - 0x0B 
  Word Address;        0x0C - 0x0D 
  Word Length;         0x0E - 0x0F 
  Byte Data[Length];   0x10 - ...  
*/

echo 'C64 CARTRIDGE   ';
echo pack('N', 0x40);
echo pack('n', 0x0100);
echo pack('n', 32);
echo pack('C', 1); // 1 here to ultimax
echo pack('C', 0);
echo pack('c*', 0, 0, 0, 0, 0, 0);
echo substr(str_pad('ALeX\'s <DOT>CRT Loader', 32, chr(0)), 0, 32);

if($MODE[3] && count($mod256k) == 0){
	fail("ocean mode requires an ocean crt\n");
}

$o_banks = $MODE[3] ? ceil((filesize($mod256k[1])-64) / (0x2000 + 16)) : 0;
$bank = $MODE[3] ? $o_banks : 1;

// initialize directory structure
$DIR = array();

// initialize all baks as empty
$CHIPS = array(0 => array(), 1 => array());

// add EasyLoader (stdin) to a bank
$CHIPS[$MODE[2] ? 1 : 0][$MODE[0]] = $f;

for($b = 2; $b < $o_banks; ){
	if($b < 16){
		// ultimax 8k is free
		$FREE_SORT_BANKS = min($o_banks, 16) - $b;
		usort($dir_crt8u, 'sort_dirs_crt');
		if(count($dir_crt8u) > 0 && count($dir_crt8u[0]['data']) <= $FREE_SORT_BANKS){
			$E = array_shift($dir_crt8u);
			$E['dir'][1] = $b;
			$DIR[] = $E['dir'];
			foreach($E['data'] AS $d){
				$CHIPS[1][$b++] = $d;
			}
		}else if(count($dir_prg8) > 0){
			$E = array_shift($dir_prg8);
			$E['dir'][1] = $b;
			$E['dir'][4] = 0x2000; // upper half of a bank
			$DIR[] = $E['dir'];
			$CHIPS[1][$b++] = $E['data'];
		}else{
			if($config['show_free']){
				show('on bank '.$b.' is a ultimax 8k crt free (ocean mode)');
			}
			$b++;
		}
	}else{
		// norm 8k is free
		$FREE_SORT_BANKS = $o_banks - $b;
		usort($dir_crt8, 'sort_dirs_crt');
		if(count($dir_crt8) > 0 && count($dir_crt8[0]['data']) <= $FREE_SORT_BANKS){
			$E = array_shift($dir_crt8);
			$E['dir'][1] = $b;
			$DIR[] = $E['dir'];
			foreach($E['data'] AS $d){
				$CHIPS[0][$b++] = $d;
			}
		}else if(count($dir_prg8) > 0){
			$E = array_shift($dir_prg8);
			$E['dir'][1] = $b;
			$E['dir'][4] = 0x0000; // lower half of a bank
			$DIR[] = $E['dir'];
			$CHIPS[0][$b++] = $E['data'];
		}else{
			if($config['show_free']){
				show('on bank '.$b.' is a normal 8k crt free (ocean mode)');
			}
			$b++;
		}
	}
}

while(count($dir_crt8) > 0 || count($dir_crt8u) > 0){
	if(count($dir_crt8) > 0 && count($dir_crt8u) > 0){
		// we have normal and ultimax 8k crt's: just put them together
		$N = array_shift($dir_crt8);
		$M = array_shift($dir_crt8u);
		$N['dir'][1] = $bank;
		$DIR[] = $N['dir'];
		$M['dir'][1] = $bank;
		$DIR[] = $M['dir'];
		for($b=0; $b<max(count($N['data']), count($M['data'])); $b++){
			if($b < count($N['data'])){
				$CHIPS[0][$bank] = $N['data'][$b];
			}
			if($b < count($N['data'])){
				$CHIPS[1][$bank] = $M['data'][$b];
			}
			$bank++;
		}
	}else if(count($dir_crt8) > 0){
		// we have normal crt's: try to fill with prgs (<=8k)
		$M = array_shift($dir_crt8);
		$P = array_shift($dir_prg8);
		$M['dir'][1] = $bank;
		$DIR[] = $M['dir'];
		if($P != null){
			$P['dir'][1] = $bank;
			$P['dir'][4] = 0x2000; // upper half of a bank
			$DIR[] = $P['dir'];
			$CHIPS[1][$bank] = $P['data'];
		}else{
			if($config['show_free']){
				show('on bank '.$b.' is a ultimax 8k crt free');
			}
		}
		foreach($M['data'] AS $d){
			$CHIPS[0][$bank++] = $d;
		}
	}else{
		// we have ultimax crt's: try to fill with prgs (<=8k)
		$M = array_shift($dir_crt8u);
		$P = array_shift($dir_prg8);
		$M['dir'][1] = $bank;
		$DIR[] = $M['dir'];
		if($P != null){
			$P['dir'][1] = $bank;
			$P['dir'][4] = 0x0000; // lower half of a bank
			$DIR[] = $P['dir'];
			$CHIPS[0][$bank] = $P['data'];
		}else{
			if($config['show_free']){
				show('on bank '.$b.' is a ultimax 8k crt free');
			}
		}
		foreach($M['data'] AS $d){
			$CHIPS[1][$bank++] = $d;
		}
	}
}

foreach($more_crt16 AS $file => $name){
	$CHIPS[0][$bank] = substr(file_get_contents($file), -16*1024, 8*1024);
	$CHIPS[1][$bank] = substr(file_get_contents($file), -8*1024);

	$DIR[] = array(
		$name,
		$bank,
		0x11 | $is_hidden[$file],
		16*1024,
		0,
	);

	$bank++;
}

foreach($more_mmc16 AS $file => $name){
	$mmc = file_get_contents($file);
	$mmc_banks = ceil(strlen($mmc) / 0x4000);
	for($i=0; $i<$mmc_banks; $i++){
		$CHIPS[0][$bank+$i] = substr($mmc, $i*0x4000,          0x2000);
		$CHIPS[1][$bank+$i] = substr($mmc, $i*0x4000 + 0x2000, 0x2000);
	}

	$DIR[] = array(
		$name,
		$bank,
		0x11 | $is_hidden[$file],
		$mmc_banks * 0x4000,
		0,
	);

	$bank+=$mmc_banks;
}

foreach($more_crt16u AS $file => $name){
	$CHIPS[0][$bank] = substr(file_get_contents($file), -16*1024, 8*1024);
	$CHIPS[1][$bank] = substr(file_get_contents($file), -8*1024);

	$DIR[] = array(
		$name,
		$bank,
		0x12 | $is_hidden[$file],
		16*1024,
		0,
	);

	$bank++;
}


// add some files
$start = $bank << 14;
$data = '';
foreach(array_merge($dir_prg8, $dir_prg) AS $E){
	$E['dir'][1] = $start >> 14;
	$E['dir'][4] = $start & 0x3fff;
	$DIR[] = $E['dir'];
	$data .= $E['data'];
	$start += strlen($E['data']);
}

// find *'s
foreach($more_m2i AS $k => $file){
	if(substr($file, -1) == '*'){
		unset($more_m2i[$k]);
$dh = opendir(substr($file, 0, -1));
while($e = readdir($dh)){
	if($e[0] != '.') // no dot-files
		$more_m2i[] = substr($file, 0, -1).$e;
}
closedir($dh);
	}
}
// add some m2i
foreach($more_m2i AS $file){

	if(substr($file, -1) == '$'){
$all_m2i = array();
$dh = opendir(substr($file, 0, -1));
while($e = readdir($dh)){
	if($e[0] != '.') // no dot-files
		$all_m2i[] = substr($file, 0, -1).$e;
}
closedir($dh);

		for(;;){
			shuffle($all_m2i);
			$file = $all_m2i[0];
			$m2i = read_m2i($file);
			for($i=0; ($l=$m2i[$i]) && (strtolower($l[1]) != 'p'); $i++);
			if(filesize($file.'/'.trim($l[2])) < 21*1024)
				break;
		}
	}

	$m2i = read_m2i($file);

	$m2i_dir = '';
	$m2i_data = '';
	$m2i_dir_address = $start;
	$start += (count($m2i)+1)*24;
	foreach($m2i AS $r){
//fail(var_export($r, 1));
		if(strtolower($r[1]) == 'p' || strtolower($r[1]) == 's'){
			// program
			$d = file_get_contents($file.'/'.trim($r[2]));
			$D = array(
				$r[3],
				$start >> 14,
				0x01,
				strlen($d),
				$start & 0x3fff
			);
			$start += strlen($d);
			$m2i_data .= $d;
		}else{
			// deleted
			$D = array(
				$r[3],
				0,
				0x03,
				0,
				0
			);
		}
		$m2i_dir .= (str_pad(rtrim(substr($D[0], 0, 16)), 16, chr(0))); // name
		$m2i_dir .= chr($D[2]); // type
		$m2i_dir .= chr($D[1]); // bank
		$m2i_dir .= pack('v', $D[4]); // offset, unused for crt
		$m2i_dir .= substr(pack('V', $D[3]), 0, 3); // size (encode 32, take 24)
		$m2i_dir .= chr(0x00); // pad
	}
	
	$m2i_dir .= str_repeat(chr(0xff), 24); // end of dir marker
	$m2i_dir .= $m2i_data; // attach files
	
	show(sprintf('m2i file %s at bank $%02x addr $%04x', $file, $m2i_dir_address >> 14, 0x8000+($m2i_dir_address & 0x3fff)));

	$DIR[] = array(
		basename($file),
		$m2i_dir_address >> 14,
		0x02 | $is_hidden[$file],
		strlen($m2i_dir),
		$m2i_dir_address & 0x3fff,
	);
	$data .= $m2i_dir;
}

for($o=0; $o<strlen($data); $o+=16*1024){
	$CHIPS[0][$bank] = substr($data, $o       , 8*1024);
	$CHIPS[1][$bank] = substr($data, $o+8*1024, 8*1024);
	$bank++;
}

if($bank > 64){
	fail("bank to big: ".$bank."\n");
}

if($MODE[3]){
	$DIR[] = array(
		$mod256k[0],
		0,
		0x11 | $is_hidden[$file],
		$o_banks*0x2000,
		0,
		true,
	);
	$f = fopen($mod256k[1], 'r');
	fread($f, 64); // skip crt header
	for($i=0; $i<$o_banks; $i++){
		fread($f, 16); // skip chip header
		$CHIPS[$i >> 4][$i] = fread($f, 8*1024);
	}
}

// CREATE DIR

$dir = '';
foreach($DIR AS $D){
	$dir .= repair_case(str_pad(rtrim(substr($D[0], 0, 16)), 16, chr(0))); // name
	$dir .= chr($D[2]); // type
	$dir .= chr($D[1]); // bank
	$dir .= chr(0x00); // pad
	$dir .= pack('v', $D[4]); // offset, unused for crt
	$dir .= substr(pack('V', $D[3]), 0, 3); // size (encode 32, take 24)
}

// padding with 255 means also type 255 = EOF
$dir = str_pad($dir, 8*1024, chr(0xff));

//if($MODE[3]){
	$ult = ultimax_loader($MODE[0], $MODE[2]);
	if($MODE[1] == 0){
		$dir = substr($dir, 0, 8*1024 - strlen($ult)).$ult;
	}else{
		$CHIPS[1][0] = str_pad($ult, 8*1024, chr(0), STR_PAD_LEFT);
	}
//}

$CHIPS[1][$MODE[1]] = $dir;


ksort($CHIPS[0]);
foreach($CHIPS[0] AS $i => $dummy){
	echo 'CHIP';
	echo pack('N', 0x10 + 8*1024);
	echo pack('n', 0x0000);
	echo pack('n', $i);
	echo pack('n', 0x8000);
	echo pack('n', 8*1024);
	echo str_pad(substr($CHIPS[0][$i], 0, 8*1024), 8*1024, chr(0xff));
}
ksort($CHIPS[1]);
foreach($CHIPS[1] AS $i => $dummy){
	echo 'CHIP';
	echo pack('N', 0x10 + 8*1024);
	echo pack('n', 0x0000);
	echo pack('n', $i);
	echo pack('n', 0xa000);
	echo pack('n', 8*1024);
	echo str_pad(substr($CHIPS[1][$i], 0, 8*1024), 8*1024, chr(0xff));
}

if($config['show_free']){
	show((64-$bank)." banks free");
}

if($config['show_memmap']){
	$DIR[] = array(
		'EasyFS + Startup',
		0,
		0x13 | 0x80,
		0x2000,
		0
	);
	$DIR[] = array(
		'EasyLoader',
		$MODE[2] ? 1 : 0,
		($MODE[2] ? 0x13 : 0x10) | 0x80,
		0x2000,
		0
	);

	$d = array();
	foreach($DIR AS $E){
		switch($E[2] & 0x1f){
			case 0x10:
				$crt = true;
				$bs = 8*1024;
				$lo = true;
				$hi = false;
				break;
			case 0x11:
				$crt = true;
				$bs = 16*1024;
				$lo = true;
				$hi = true;
				break;
			case 0x12:
				$crt = true;
				$bs = 16*1024;
				$lo = true;
				$hi = true;
				break;
			case 0x13:
				$crt = true;
				$bs = 8*1024;
				$lo = false;
				$hi = true;
				break;
			case 0x01:
				$crt = false;
				break;
			default:
				fail('unknown dir-type: '.($E[2] & 0x1f));
		}
		if($crt){
			// crt mode
			$banks = $E[3] / $bs;
			if(isset($E[5])){
				// ocean mode
				for($b=0; $b<$banks*2; $b++){
					$d[$E[1] + $b][($b < 16) ? 0 : 1][0] = array($E[0].' (Ocean Cart)', 0x2000);
				}
			}else{
				for($b=0; $b<$banks; $b++){
					if($lo){
						$d[$E[1] + $b][0][0] = array($E[0].' ('.($banks*$bs/0x400).'k Cart)', 0x2000);
					}
					if($hi){
						$d[$E[1] + $b][1][0] = array($E[0].' ('.($banks*$bs/0x400).'k Cart)', 0x2000);
					}
				}
			}
		}else{
			// program mode
			$start = ($E[1] << 14) | $E[4];
			$len = $E[3];
			while($len > 0){
				$l = min($len, 0x2000 - ($start & 0x1fff));
				$d[$start >> 14][($start >> 13) & 1][$start & 0x1fff] = array($E[0], $l);
				$start += $l;
				$len -= $l;
			}
		}
	}
	ksort($d);
	while(count($d) < 64){
		$d[] = array();
	}
	$WIDTH = $config['memmap_colwidth'];
	$MINW = 1;
	$report = '';
	foreach($d AS $ba_nr => $ba_da){
		$report .= 'Bank '.sprintf('%2d', $ba_nr).' | ';
		for($lh_id = 0; $lh_id < 2; $lh_id++){
			$lh_data = isset($ba_da[$lh_id]) ? $ba_da[$lh_id] : array();
			$size = 0;
			foreach($lh_data AS $d){
				$size += $d[1];
			}
			if($size != 0x2000){
				$lh_data[] = array(str_repeat('*', 40), 0x2000 - $size);
			}
			$freesize = $WIDTH-2*(count($lh_data) - 1);
			$freelen = 0x2000;
			foreach($lh_data AS $k => $d){
				$s = round($WIDTH * $d[1] / 0x2000);
				$mw = min($MINW, strlen($d[0])+1);
				if($s < $mw){
					$lh_data[$k][2] = $mw;
					$freesize -= $mw;
					$freelen -= $d[1];
				}
			}
			foreach($lh_data AS $k => $d){
				if($k > 0){
					$report .= ', ';
				}
				if(!isset($d[2])){
					$d[2] = round($freesize * $d[1] / $freelen);
				}
				$report .= substr($d[0].str_repeat('_', $WIDTH), 0, $d[2]);
			}
			$report .= " | ";
		}
		$report .= "\n";
	}
	show($report);
}

function repair_case($t){
	for($i=0; $i<strlen($t); $i++){
		$o = ord($t[$i]);
		if($o >= 0x41 && $o <= 0x5a){
			$t[$i] = chr($o + 0x20);
		}else if($o >= 0x61 && $o <= 0x7a){
			$t[$i] = chr($o - 0x20);
		}
		if($o >= 0xc1 && $o <= 0xda){
			$t[$i] = chr($o + 0x20);
		}else if($o >= 0xe1 && $o <= 0xfa){
			$t[$i] = chr($o - 0x20);
		}
	}
	return $t;
}

function ultimax_loader($bank, $hiaddr){
	if($bank == 0 && !$hiaddr){
		return file_get_contents('tools/easyloader_launcher_nrm.bin');
	}else if($bank == 1 && $hiaddr){
		return file_get_contents('tools/easyloader_launcher_ocm.bin');
	}else{
		fail('unallowed bank/offset combination');
	}
}

function show($text){
	file_put_contents('php://stderr', $text."\n");
}

function fail($text){
	show($text);
	exit(1);
}

function read_m2i($file){
	$dh = opendir($file.'/');
	while(($m = readdir($dh)) !== false){
		if(strlen($m) > 4 && substr(strtolower($m), -4) == '.m2i')
			break;
	}

	if($m === false){
		fail('unable to find a m2i: '.$file);
	}

	$m2i = split("[\r\n]+", file_get_contents($file.'/'.$m));
	array_shift($m2i); // drop first line
	foreach($m2i AS $k => $v){
		if(trim($v) == ''){
			unset($m2i[$k]);
		}
	}
	$RET = array();
	foreach($m2i AS $ln){
		if(!preg_match('!^([pds]):([^:]*):(.*)$!i', $ln, $r)){
			fail('umable to decode '.var_export($ln, 1));
		}
		$RET[] = $r;
	}
	return $RET;
}

function sort_dirs_crt($a, $b){
	global $FREE_SORT_BANKS;
	$la = count($a['data']) <= $FREE_SORT_BANKS;
	$lb = count($b['data']) <= $FREE_SORT_BANKS;
	if($la == $lb){
		return count($b['data']) - count($a['data']);
	}else if($la){
		return -1;
	}else{
		return 1;
	}
}

function sort_dirs_prg($a, $b){
	return strlen($b['data']) - strlen($a['data']);
}

?>