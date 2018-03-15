<?php
namespace infrajs\mark;
use infrajs\once\Once;
use infrajs\sequence\Sequence;
use infrajs\load\Load;
use infrajs\each\Each;
use infrajs\path\Path;
use infrajs\ans\Ans;
/**
 * Класс обеспечивает негарантированное хранение параметров в экстрокороткой строке из ~2 символов
 * Это работает за счёт сохранения объекта данных в 2х символах, со временем данные этих ~2х символов буду заменены, но нам важно только короткая память
 * возможность обменяться ссылками, кнопки вперёд назад.
 * так называемая приставка окружения env содержит в себе зашифрованную часть (~2 символа) и изменение к зашифрованной части
 * например ?Каталог:aa содержит защифрованную часть aa которая на сервере развернётся в объект данных {page:1,prod:"Арсенал"}
 * например aa:page:2 - зашифрованная часть aa объединится с изменениями и получится {page:2,prod:"Арсенал"}
 * объект данных {page:2,prod:"Арсенал"} зашифруется в новую комбинацию xx и дальнейшие ссылки уже относительно этой пары символов
 * $mark=new Mark($str); //$str содержит приставку
 * $val=$mark->getVal();
 * $fd=$mark->getData();
 * Проверить $fd
 * $mark->setData($fd);
 * $mark=$mark->getMark(); //приставка для следующего $str
 */
class Mark
{
	public $sym = ':';
	public $symeq = '=';
	//Если метка есть а даных нет считаем что метка устарела.
	//Недопускаем ситуации что метка появилась до появления привязанных к ней данных

	
	public $add = array();
	public $isadd = false;

	public $notice = '';//Последнее Сообщение о проблеме
	public $error = '';//Последнее Сообщение о проблеме
	
	public $mark = false; //Если были внесены изменения то тут хранится новая метка
	public $data = array();
	public $checkchange = false; //Метка было ли изменение данных
	public $len = 2; //Хэшмарк стартовая длина
	public $raise = 6; //Хэшмарк На сколько символов разрешено увеличивать хэш
	public $dir = '~.marks/'; //Имя дирректорию где хранятся метки
	public $checkmark = ''; //Старая метка для массива данных
	public $checkdata = false; //Результат последней проверки данных, были ли ошибки
	public function getVal()
	{
		if ($this->mark === false) {
			if ($this->checkdata) {//Восстановлены или устанавливаются хоть какие-то данные
				if ($this->checkmark) { //Что-то восстановленное
					if ($this->checkchange) { //Были какие-то установки
						$this->mark = $this->makeMark($this->data);
					} else {
						$this->mark = $this->checkmark;
					}
				} else { //Что-то новое
					$this->mark = $this->makeMark($this->data);
				}
			} else { //Всё определено по умолчанию
				$this->mark = '';
			}
		}
		return $this->mark;
	}
	public function getData($newdata = null)
	{
		return $this->data;
	}
	public function setData($newdata, $checkmark = false, $checkchange = false)
	{
		$this->mark = false;
		$this->data = $newdata; //Это разрешае взаимный вызов в определяющих функциях в add при правильном порядке dependencies. в region можно обратиться к lang.
		$this->data = $this->check($newdata, $checkmark, $checkchange);
		static::rksort($this->data);
	}
	private $props = array();
	public function add($name, $fndef, $fncheck)
	{
		$this->props[$name] = array(
			'fndef' => $fndef,
			'fncheck' => $fncheck
		);
	}
	public function check(&$data, $checkmark, $checkchange)
	{
		$this->checkmark = $checkmark;
		$this->checkdata = !!$data;
		$this->checkchange = $checkchange;
		if (!is_array($data)) $data = array();
		foreach ($data as $k => $v) {
			if (!isset($this->props[$k])) unset($data[$k]);
		}
		foreach ($this->props as $k => $v) {
			if (!isset($data[$k]) || !$v['fncheck']($data[$k])) {
				$data[$k] = $v['fndef']();
			}
		}
		return $data;
	}
	public function setVal($mark)
	{
		//Восстанавливаем метку по переданному значению
		$mark = preg_replace("/:(.+)::\./U", ":$1::$1.", $mark);
		$r = explode($this->sym, $mark);
		$checkmark = array_shift($r);
		$origdata = array();
		if ($checkmark != '') {
			$src=Path::theme($this->dir.$checkmark.'.json');
			if ($src) {
				$data = file_get_contents($src);
				$data = Load::json_decode($data);
			} else {
				$data = array();
			}

			if ($data && is_array($data['data']) && $data['time']) {
				$origdata = $data['data'];
			}
		}
	
		$data = $origdata; //Восстановили старые значения (или нет)
		
		$add = implode($this->sym, $r);
		$this->change = false;
		if ($add !== ''){
			$this->change = true;
			$checkmark = false; //Раз мы что-то добавляем старая марка точно не подойдёт
			$r = explode($this->sym, $add);
			$l = sizeof($r);
			if ($this->sym == $this->symeq) {
				if ($l%2) {
					$l++;
					$r[] = '';
				}
				for ($i = 0; $i < $l; $i = $i + 2) {
					if (!$r[$i]) continue;
					Sequence::set($data, Sequence::right($r[$i]), $r[$i+1]);
				}
			} else {
				for ($i = 0; $i < $l; $i = $i + 1) {
					if (!$r[$i]) continue;
					$rr = explode($this->symeq, $r[$i], 2);
					if (!$rr[0]) continue;
					Sequence::set($data, Sequence::right($rr[0]), $rr[1]);
				}
			}
		}
		$this->setData($data, $checkmark);	
	}
	public function __construct($dir)
	{
		$this->dir = $dir;	
	}
	private function rksort(&$data){
		ksort($data);
		foreach($data as &$v){
			if(!is_array($v))continue;
			if(Each::isAssoc($v)===true) self::rksort($v);
		}
	}
	private function makeMark($data)
	{
		
		if (!$data) return '';
		//self::rksort($data);
		$key = md5(Load::json_encode($data));
		$mark = Once::exec($this->dir.$key, function () use ($data, $key) {
			$raise = $this->raise; //На сколько символов разрешено увеличивать хэш

			$len = $this->len-1;
			while ($len < $this->len + $raise) {
				$len++;
				$mark = substr($key, 0, $len);
				$src = Path::theme($this->dir.$mark.'.json');
				if ($src) {
					$otherdata = file_get_contents($src);
					$otherdata = Load::json_decode($otherdata, true);	
				} else {
					$otherdata = false;
				}

				if ($otherdata && is_array($otherdata['data']) && $otherdata['time']) {
					if ($otherdata['data'] == $data) {
						return $mark; //Такая метка уже есть и она правильная
					}
				} else {
					break; //Выходим из цикла и создадим запись о метке
				}
			}

			if ($len == $this->len + $raise) {
				//достигнут лимит по длине... 
				$this->error = 'Mark rewrite actual hashmark';
				error_log($this->error);
				$mark = substr($key, 0, $this->len); //перезаписываем первую
			}
			
			$src = Path::theme($this->dir);
			if (!$src) die('Fatal error no marks dir');
			$src = $src.$mark.'.json';
			$data = Load::json_encode(array(
				'time'=>time(),
				'data'=>$data
			));
			$data = file_put_contents($src, $data);
			
			return $mark;
		});
		return $mark;
	}
}
