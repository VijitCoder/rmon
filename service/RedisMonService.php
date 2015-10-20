<?php
/**
 * Сервис монитора Redis
 */
class RedisMonService extends CComponent
{
    /**
     * Список ключей с ttl
     * @return JSON
     */
    public static function keysList()
    {
        //буква, цифра или _ : - . *
        $ptrn = (isset($_GET['key']) && preg_match('~^[\w\-:.*]+$~', $_GET['key'])) ? $_GET['key'] : '*';
        $keys = Yii::app()->redis->scan($ptrn);
        self::_clearKeys($keys);
        $ttls = self::ttls($keys);
        sort($keys);
        return json_encode(compact('keys', 'ttls'));
    }

    /**
     * Удаляем из списка ключей лишнее. Перманентно удаляются:
     * - 32-значный хеш - это md5 поисковой фразы. Сам-то он может и полезен, но найти нужный ключ
     * среди десятков ему подобных нереально.
     * - sess:[\w-]{32} - сессионная инфа мобильного приложения. Не нужна для отладки/мониторинга.
     * @param array &$keys
     * @return void
     */
    private static function _clearKeys(&$keys)
    {
        foreach ($keys as $k => $v) {
            if (preg_match('~^[0-9a-f]{32}|sess:[\w-]{32}~', $v)) {
                unset($keys[$k]);
            }
        }
    }

    /**
     * Получаем ttl каждого ключа
     * Здесь же применяем фильтрацию по ttl, если она задана и удаляем неподходящие ключи.
     * Возможны три фильтра ttl: больше/меньше заданного значения и значение в промежутке.
     * @param array &$keys
     * @return array
     */
    public static function ttls(&$keys)
    {
        $case = '';
        if (isset($_GET['ttl']) && $_GET['ttl']) {
            if (preg_match('~^>(\d+)$~', $_GET['ttl'], $m)) {
                $case = 'more';
                $flt = (int)$m[1];
            } elseif (preg_match('~^<(\d+)$~', $_GET['ttl'], $m)) {
                $case = 'less';
                $flt = (int)$m[1];
            } elseif (preg_match('~^(\d+)-(\d+)$~', $_GET['ttl'], $m)) {
                $case = 'gap';
                $flt = [(int)$m[1], (int)$m[2]];
            }
        }

        $ttls = array();
        foreach ($keys as $k => $v) {
            $t = (int)Yii::app()->redis->execute(['TTL', $v]);
            $takeIt = true;
            switch ($case) {
                case'':break; //так быстрее. Не будет проверки всех условий, когда фильтра нет.
                case 'more':
                    if ($t != -1 && $t < $flt) { $takeIt = false; }
                    break;
                case 'less':
                    if ($t > $flt) { $takeIt = false; }
                    break;
                case 'gap':
                    if ($t != -1 && !($t > $flt[0] AND $t < $flt[1])) { $takeIt = false; }
                    break;
                default:;
            }

            if (!$takeIt) {
                unset($keys[$k]);
            } else {
                $ttls[$v] = $t;
            }
        }
        return $ttls;
    }

    /**
     * Получаем инфу кеша по указанному ключу
     *
     * @TODO реализация для типов 'set' и 'list'
     *
     * @return json
     */
    public static function info()
    {
        if (!isset($_GET['key'])) {
            return 'нет нужного параметра';
        }

        $key = $_GET['key'];
        $type = Yii::app()->redis->execute(['TYPE', $key]);

        switch ($type) {
            case 'none':
                $result = 'ключ не найден';
                break;
            case 'hash':
                $result = self::_getHash($key);
                break;
            case 'string':
                $result = self::_getString($key);
                break;
            case 'set':;
            case 'list':;
            default: return 'Не поддерживаемый тип кеша - ' . $type;
        }

        $result = $result !== false
        ? [
            'type' => $type,
            'ttl' => Yii::app()->redis->execute(['TTL', $key]),
            'data' => empty($result) ? '&lt;пусто&gt;' : $result,
        ]
        : [
            'type' => 'none',
            'ttl' => -1,
            'data' => 'нет данных',
        ];

        return json_encode($result) ;
    }

    /**
     * Читаем строковый кеш
     * @param string $key
     * @return string
     */
    private static function _getString($key)
    {
        if ((!$result = Yii::app()->redis->execute(['GET', $key])) || isset($_GET['raw'])) {
            return $result;
        }
        return var_export(Yii::app()->redis->fullInfo($result), true);
    }

    /**
     * Читаем хеш-таблицу
     * @param string $key
     * @return string
     */
    private static function _getHash($key)
    {
        if (isset($_GET['raw'])) {
            if (!$result = Yii::app()->redis->execute(['HGETALL', $key])) {
                return false;
            }
        } else {
            $fields = Yii::app()->redis->execute(['HKEYS', $key]);
            $values = Yii::app()->redis->execute(['HVALS', $key]);

            if (!$fields || !$values) {
               return false;
            }

            $values = array_map([Yii::app()->rmon, 'fullInfo'], $values);
            $result = array_combine($fields, $values);
            ksort($result);
        }
        return  var_export($result, true);
    }

    /**
     * Новое значение TTL кешу по заданному ключу и времени жизни
     * @return int|string в случае ошибок
     */
    public static function expire()
    {
        if (isset($_POST['key']) && isset($_POST['ttl'])) {
            if (!$ttl = intval($_POST['ttl'])) {
               return 'Недопустимое значение ttl';
            }

            //Если время обновится, ответим новым значением. Иначе - тем, что сказал кешер.
            $result = Yii::app()->redis->execute(['EXPIRE', (string)$_POST['key'], $ttl]);
            $result = ($result === '1') ? $ttl : 'Ответ Redis: ' . $result;
        } else {
            $result = 'нет нужного параметра';
        }

        return $result;
    }

    /**
     * Удаление кешей по маске ключа. Запрещена маска "*", что равносильно удалению всей базы кеша
     * @return string
     */
    public static function delete()
    {
        if (isset($_POST['key'])) {
            $key = $_POST['key'];
            if($key === '*') {
                return 'Недопустимая маска. Нельзя удалить весь кеш.';
            }
            $result = Yii::app()->redis->delete($key);
            if (!is_numeric($result)) {
                $result = 'Ответ Redis: ' . $result;
            }
        } else {
            $result = 'нет нужного параметра';
        }
        return $result;
    }

    /**
     * Переключение кешера. Открываем config/main.php и прописываем настройку 'off' => true|false.
     * По умолчанию доступ к файлу ограничен, 664. Поскольку обновление main.php происходит редко,
     * можно вручную менять права. Будет сложно, буду искать решение.
     *
     * @param bool $on включить кешер?
     * @return int|string 1 - успешно, 0 - неудача ИЛИ сообщение об ошибке
     */
    public static function switcher($on)
    {
        if (!$file = realpath(__DIR__ . '/../../config/main.php')) {
            return 'Не нашел файл конфига';
        } elseif (!is_writable($file)) {
            return 'Не могу писать в конфиг';
        }

        if (($conf = file_get_contents($file)) === false) {
            return 'Ошибка чтения файла конфига';
        }

        $search = $on ? 'true' : 'false';
        $replace = $on ? 'false' : 'true';
        $conf = str_replace("'off' => {$search},", "'off' => {$replace},", $conf);
        if (strpos($conf, "'off' => {$replace},") === false) {
            return 'Не удалось переписать значение';
        }

        if (file_put_contents($file, $conf) === false) {
            return 'Ошибка записи файла конфига';
        }

        return 1;
    }
}
