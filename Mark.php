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
	
	public $mark = ''; //Если были внесены изменения то тут хранится новая метка
	public $data = array();
	public $origmark = ''; //Старая метка для массива данных
	public $origdata = array();
	
	
	public $restore = false; //Метка что данные были восстановлены
	public $change = false; //Метка было ли изменение данных
	public $len = 2; //Хэшмарк стартовая длина
	public $raise = 6; //Хэшмарк На сколько символов разрешено увеличивать хэш
	public $dir = '~.marks/'; //Имя дирректорию где хранятся метки
	public $checkfail = false; //Результат последней проверки данных, были ли ошибки
	public function getVal()
	{
		return $this->mark;
	}
	public function getData($newdata = null)
	{
		return $this->data;
	}
	public function setData($newdata, $change = true)
	{
		$this->data = $this->check($newdata);
		if($change || $this->checkfail) {
			$this->mark = $this->makeMark($this->data);
		} else {
			$this->mark = $this->origmark;
		}
		return $this->mark;
	}
	private $props = array();
	public function add($name, $fndef, $fncheck)
	{
		$this->props[$name] = array(
			'fndef' => $fndef,
			'fncheck' => $fncheck
		);
	}
	public function check($data)
	{
		$this->checkfail = false;
		if (!is_array($data)) $data = array();
		foreach ($data as $k => $v) {
			if (!isset($this->props[$k])) unset($data[$k]);
		}
		foreach ($this->props as $k => $v) {
			if (!isset($data[$k]) || !$v['fncheck']($data[$k])) {
				$this->checkfail = true;
				$data[$k] = $v['fndef']();
			}
		}
		return $data;
	}
	public function setVal($mark)
	{
		$mark = preg_replace("/:(.+)::\./U", ":$1::$1.", $mark);
		$r = explode($this->sym, $mark);
		$this->restore = false;
		$this->origmark = array_shift($r);
		$this->origdata = array();
		if ($this->origmark != '') {
			$src=Path::theme($this->dir.$this->origmark.'.json');
			if ($src) {
				$data = file_get_contents($src);
				$data = Load::json_decode($data);
				$this->restore = true;
			} else {
				$data = false;
			}

			if ($data && is_array($data['data']) && $data['time']) {
				$this->origdata = $data['data'];
			}
		}
	
		$data = $this->origdata;
		
		$add = implode($this->sym, $r);
		$this->change = false;
		if ($add !== ''){
			$this->change = true;
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
		$this->setData($data, $this->change);	
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
		self::rksort($data);
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
