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
            
            $val = isset($rr[1]) ? $rr[1] : null;
            Sequence::set($data, $rightpath, $val); //Собираем данные. Тут могут быть и null
            $paths[$shortpath] = $rightpath; //Сохраняем пути возъимевшие действие
            unset($val);
        }
        foreach ($paths as $short => $right) {
            $prop = $right[0];
            if ( empty($props[$prop]) 
                || !isset($data[$prop]) 
                || is_null($data[$prop]) 
                || $data[$prop] == '' 
                || !$props[$prop]['fncheck']($data[$prop])
            ) {
                unset($data[$prop]); //Может быть 2 шорта на один props
                unset($paths[$short]);
                continue;
            }
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
