<?php
namespace infrajs\mark;
use infrajs\once\Once;
use infrajs\sequence\Sequence;
use infrajs\load\Load;
use infrajs\each\Each;
use infrajs\path\Path;
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
	private $sym = ':';
	private $symeq = '=';
	//Если метка есть а даных нет считаем что метка устарела.
	//Недопускаем ситуации что метка появилась до появления привязанных к ней данных

	public $old = array();
	public $add = array();
	public $isadd = false;
	public $isold = false;
	public $isoutdate=null;
	public $notice = '';//Сообщение о проблеме
	private $mark = '';
	private $data = array();
	private $warrantytime = 0; //Ссылка не перезаписывается в течении 2х месяцев
	private $prefix = 'env-';
	private $len = 1; //Хэшмарк стартовая длина
	private $raise = 4; //Хэшмарк На сколько символов разрешено увеличивать хэш
	private $note = 3;//Хэшмарк При увеличении на сколько записывается сообщение в лог
	public function getVal()
	{
		return $this->val;
	}
	public function getData($newdata = null)
	{
		return $this->data;
	}
	public function setData($newdata)
	{
		$this->data=$newdata;
		$this->mark=$this->makeMark($this->data);
		return $this->mark;
	}
	public static $instances=array();
	public static function getInstance($mark)
	{
		if (isset(self::$instances[$mark])) {
			return self::$instances[$mark];
		}
		self::$instances[$mark]=new self($mark);
		return self::$instances[$mark];
	}
	private function __construct($mark)
	{
		$this->warrantytime=60*60*24*60;//60 дней
		$mark=preg_replace("/:(.+)::\./U", ":$1::$1.", $mark);
		$r=explode($this->sym, $mark);
		$this->mark=array_shift($r);
		if ($this->mark!='') {

			$src=Path::theme('~.marks/'.$this->prefix.$this->mark.'.json');
			if ($src) {
				$data = file_get_contents($src);
				$data = Load::json_decode($data);
			} else {
				$data = false;
			}

			//$data=infra_mem_get($this->prefix.$this->mark);

			if (!$data || !is_array($data['data'])) {
				$this->mark='';
			} else {
				if (!$data['time']) {
					$data['time']=time();
				}
				$this->isold=true;

				$this->isoutdate=(time()>$data['time']+$this->warrantytime);

				$this->old=$data['data'];
			}
		}
		
		$this->data=$this->old;
		
		$add=implode($this->sym, $r);
		if($add !== ''){
			$r = explode($this->sym, $add);
			$l = sizeof($r);
			if ($this->sym == $this->symeq) {
				if ($l%2) {
					$l++;
					$r[] = '';
				}
				for ($i = 0; $i < $l; $i = $i + 2) {
					if (!$r[$i]) continue;
					Sequence::set($this->data, Sequence::right($r[$i]), $r[$i+1]);
				}
			} else {
				for ($i = 0; $i < $l; $i = $i + 1) {
					if (!$r[$i]) continue;
					$rr = explode($this->symeq, $r[$i], 2);
					if (!$rr[0]) continue;
					Sequence::set($this->data, Sequence::right($rr[0]), $rr[1]);
				}
			}
		}
	}
	private function rksort(&$data){
		ksort($data);
		foreach($data as &$v){
			if(!is_array($v))continue;
			if(Each::isAssoc($v)===true) self::rksort($v);
			//else sort($v);
		}
	}
	private function makeMark($data)
	{
		
		if (!$data) return '';
		self::rksort($data);
		
		$key=md5(serialize($data));
		$that=$this;

		$mark=Once::exec($this->prefix.$key, function () use ($data, $that, $key) {
			$isoutdate=true;
			$raise=$this->raise; //На сколько символов разрешено увеличивать хэш
			$note=$this->note;//При увеличении на сколько записывается сообщение в лог

			$len=$this->len-1;
			while ($isoutdate&&$len<$this->len+$raise) {
				$len++;
				$mark=substr($key, 0, $len);
				
				
				$src=Path::theme('~.marks/'.$that->prefix.$mark.'.json');
				if ($src) {
					$otherdata = file_get_contents($src);
				
					$otherdata = Load::json_decode($otherdata,true);	
					
				} else {
					$otherdata = false;
				}

				//$otherdata=infra_mem_get($that->prefix.$mark);

				if ($otherdata && is_array($otherdata['data']) && $otherdata['time']) {
					if ($otherdata['data']==$data) {
						$isoutdate=false;//Такая метка уже есть и она правильная
					} else {
						//Решается судьба старой метки
						$isoutdate=(time()>$data['time']+$this->warrantytime);
					}
				} else {
					$isoutdate=false;
				}
			}

			if ($len>=$this->len+$note) {
				$that->notice='Mark adding to hash '.($len-$this->len).' symbol(s) for save time warranty '.print_r($data, true);
				error_log($that->notice);
			}
			if ($isoutdate) {
				//Все метки актуальны... перезаписываем первую
				$that->error='Mark rewrite actual hashmark';
				error_log($that->error);
				$mark=substr($key, 0, $this->len);
			}

			$src=Path::theme('~.marks/');
			if (!$src) die('Fatal error no marks dir');
			$src=$src.$this->prefix.$mark.'.json';
			$data = Load::json_encode(array(
				'time'=>time(),
				'data'=>$data
			));
			$data = file_put_contents($src, $data);
			

			//infra_mem_set($that->prefix.$mark, array(
			//	'time'=>time(),
			//	'data'=>$data
			//));
			
			return $mark;
		});
		return $mark;
	}
}
