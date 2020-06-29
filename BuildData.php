<?php

namespace infrajs\mark;

use infrajs\sequence\Sequence;

class BuildData
{
    public static function init($props, $name)
    {
        $name = preg_replace("/([^:]+)::\./U", "$1:$1.", $name); //Поддержка синтаксиса "test::.lang=ru" -> "test:test.lang=ru"

        $r = explode(':', $name);

        $data = [];
        $paths = [];
    
        for ($i = 0, $l = sizeof($r); $i < $l; $i++) {
            if (!$r[$i]) continue;
            $rr = explode('=', $r[$i], 2);

            if (!$rr[0]) continue;
            $shortpath = $rr[0];
            $rightpath = Sequence::right($shortpath);
            $prop = $rightpath[0];
            
            $val = isset($rr[1]) ? $rr[1] : null;
            
            if (empty($props[$prop])) continue; //удаляем свойства о которых нет информации
            if (!$props[$prop]['fncheck']($val)) continue; //Не пройдена проверка, удаляем
            
            $paths[$shortpath] = $rightpath; //Сохраняем пути возъимевшие действие

            Sequence::set($data, $rightpath, $val); //Собираем данные. Тут могут быть и null
            unset($val);
            
        }
        
        ksort($paths);
        $unique = [];
        foreach ($paths as $shortpath => $rightpath) {
            $val = Sequence::get($data, $rightpath);
            if (is_null($val)) continue; //Для актуального path нужно знать установлено ли значение по path-пути 
            if (is_array($val)) continue; //Массивы не устанавливаем, так как будет path для их внутреннего свойства
            $unique[] = $shortpath . '=' . $val;
        }
        $name = implode(':', $unique);


        foreach ($props as $k => $v) {
            if (isset($data[$k])) continue;
            $data[$k] = $v['fndef']($data); //Region может посмотреть что в IP по этому передаём $data
        }

        return ['name' => $name, 'data' => $data];
    }
}
