<?php
/**
 * Обертка для Redis. Версия без логирования запросов
 *
 * Донор: CRedisCache.php [/framework/caching/]. Из него взял саму суть: соединение и работа с Redis
 * через сокет, парсинг ответа
 *
 * Команды Redis {@link http://redis.io/commands}
 */
class RedisSocWrapper extends CComponent
{
    /**
     * Время в сек, после которого кеш считается устаревшим, клиенту отвечаем false, что приведет к
     * обновлению кеша. Текущий ttl продлевается на эти же 120 сек, как буферное время, пока кеш
     * обновляется.
     */
    const THRESHOLD = 120;

    /** @var resource сокет-соединение с Redis */
    private $_socket;

    /**
     * @var string строка подключения к Redis-серверу
     */
    public $connStr = '127.0.0.1:6379';

    /**
     * номер базы Redis, к которой следует подключаться
     */
    public $base = 0;

    /**
     * @var bool главный выключатель. Если расширение выбросит исключение, отменять дальнейшие обращения
     * к серверу. Это исключит ситуацию, когда перегруженный сервер вдруг ответил и мы получили неполное
     * заполнение объекта из кеша
     */
    public $off = false;

    /**
     * инициализация компонента
     * метод init настоятельно рекоммендуют использовать вместо метода __construct(). В конце этого
     * метода нужно вызвать родительский метод init(). Этот метод используются в основном для
     * создания/инициализации каких-то дополнительных объектов, свойств и тд.
     */
    public function init()
    {
        //Если кешер не отключен настройкой, тогда пытаемся соединиться.
        if (!$this->off) {
            $this->_socket = @stream_socket_client($this->connStr, $errNumber, $errDescription);
            if (!$this->_socket) {
                $this->off = true;
                $msg = Yii::t('app', 'Fail to connect to Redis server. Connection string {con}. Error: {err}',
                    ['{con}' => $this->connStr, '{err}' => $errDescription]);
                $this->_errorHandler($msg, $errNumber);
            } else {
                //прим.: тут не ловим ошибку, ее раньше поймает парсер ответа.
                $this->execute(['SELECT', $this->base]);
            }
        }
    }

    /**
     * Выполнить команду Redis
     *
     * @param array $parts команда с параметрами. Принцип простой: реальную redis-команду разбиваем
     * в массив по пробелам и в таком виде передаем сюда. Все :) Строковые ключи $parts здесь тоже
     * разбираются и передаются как часть команды.
     *
     * @return array|bool|null|string Зависит от команды
     * Возращает данные разных типов:
     *   - true  - для команд, возвращающих статусы. @TODO о чем речь?
     *   - string - для команд, возвращающих "integer" в качестве значения в пределах 64-битного целого со знаком.
     *   - string | null - для команд, возвращающих данные пачкой ("bulk reply").
     *   - array - для команд, возвращающих "Multi-bulk replies"
     *
     * @throws CException для команд, возвращающих ошибку {@link http://redis.io/topics/protocol#error-reply}
     */
    public function execute($parts)
    {
        if ($this->off) {
            return null;
        }
//echo '<hr>'; VarDumper::dump($parts);//DBG
        $command = '';
        $cnt = 0; //сколько всего частей (команда, ключ, данные, что угодно) будет передано Redis
        foreach ($parts as $key => $arg) {
            //строковые ключи массива параметров тоже передаем. Предположительно это запись хеша.
            if (is_string($key)) {
                $command .= '$' . $this->_countBytes($key) . "\r\n" . $key . "\r\n";
                $cnt++;
            }
            $command .= '$' . $this->_countBytes($arg) . "\r\n" . $arg . "\r\n";
            $cnt++;
        }
        $command = '*' . $cnt . "\r\n" . $command;

//echo "<br>{$command}<br>";//DBG

        fwrite($this->_socket, $command);

        return $this->parseResponse();
    }

    /**
     * Читает результат из сокета и парсит его
     *
     * Рекурсия
     *
     * @return array|bool|null|string
     * @throws CException в случае ошибок сокета или данных
     */
    private function parseResponse()
    {
        if ($this->off) {
            return null;
        }

        if (($line = fgets($this->_socket)) === false) {
            $this->_errorHandler(Yii::t('app', 'Failed reading data from redis connection socket.'));
            return null;
        }

//VarDumper::dump($line); echo '<br>';//DBG

        $type = $line[0];
        $line = substr($line, 1, -2);
        switch ($type) {
            // В ответе - статус. Только 'OK' заменяем на true, остальные статусы передаем, как есть.
            // см. например ответ на команду TYPE в мониторе кеша.
            case '+':
                return $line === 'OK' ? : $line;
            case '-': // Error reply
                $this->_errorHandler(Yii::t('app', 'Redis error: ') . $line);
                return null;
            case ':': // Integer reply
                // no cast to int as it is in the range of a signed 64 bit integer
                return $line;
            case '$': // Bulk replies
                if ($line == '-1') {
                    return false;
                }
                $length = $line + 2;
                $data = '';
                while ($length > 0) {
                    if (($block = fread($this->_socket, $length)) === false) {
                        $this->_errorHandler(Yii::t('app', 'Failed reading data from redis connection socket.'));
                        return null;
                    }
                    $data .= $block;
                    $length -= $this->_countBytes($block);
                }
                return substr($data , 0, -2);
            case '*': // Multi-bulk replies
                $data = array();
                for($i=0; $i < (int)$line; $i++)
                    $data[] = $this->parseResponse();
                return $data;
            default:
                $this->_errorHandler(Yii::t('app', 'Unable to parse data received from redis'));
                return null;
        }
    }

    /**
     * Считаем количество байт в строке
     * Вынес отдельно, чтоб четко обозначить проблему. Из-за перегрузки функций, strlen() уже не работает,
     * ее подменяет mb_strlen(). Это был странный баг, {@see http://waredom.ru/170}
     * @param string
     * @return int
     */
    private function _countBytes($str)
    {
        return mb_strlen($str, '8bit');
    }

    /**
     * Обработчик ошибок
     * @param string $msg сообщение
     * @param int $errNumber номер ошибки (если есть)
     */
    private function _errorHandler($msg, $errNumber = 0)
    {
        $this->off = true;
        throw new CException($msg, (int)$errNumber);
    }

    /**
     * Запись хеша в redis. Принимаем массив полей/значений. Пишем "пачкой" (multiSet)
     * Новые поля дописываются. Старые - перезаписываются. TTL обновляется при любой записи.
     * См. так же HSETNX
     *
     * Возращаемые значения:
     * null - жесткое условие. Если что-то не записалось, нужно прервать кеширование. При исключении
     * возращается null.
     *
     * false - условие мягче. Запись не удалась, но исключения нет. Пока не представляю, когда такое
     * возможно. Тем не менее клиентский код должен различать возращаемое значение.
     *
     * @param string $key
     * @param array $data ассоциативный массив 'поле'=>'значение'
     * @param int $ttl срок жизни хеша в секундах. 0 = пожизненный кеш.
     * @return bool|null
     */
    public function writeHash($key, $data, $ttl = 300)
    {
        if ($this->off) {
            return null;
        }

        array_unshift($data, 'HMSET', $key);
        if ($result = $this->execute($data)) {
            if ($ttl)
                $this->execute(['EXPIRE', $key, $ttl]);
            else
                $this->execute(['PERSIST', $key]);
        }

        return $result;
    }

    /**
     * Чтение хеша из redis. Можно прочитать только одно поле из хеш-таблицы.
     *
     * Внимание! При чтении поля и маленьком ttl будет возращено false и УВЕЛИЧЕН срок ttl. Клиентский
     * код при получении такого значения должен обновить весь хеш, а не только одно поле.
     *
     * @param string $key ключ хеш-таблицы
     * @param string $field поле в таблице
     * @return mixed либо массив данных, либо строка (при чтении поля), либо false|null
     */
    public function readHash($key, $field = null)
    {
        if ($this->off) {
            return null;
        }

        if ($field) {
            $value = $this->execute(['HGET', $key, $field]);
        } else {
            if ($rawData = $this->execute(['HGETALL', $key])) {
                $value = array();
                foreach ($rawData as $k => $v) {
                    if ($k % 2 == 0) {
                        $idx = $v;
                    } else {
                        $value[$idx] = $v;
                    }
                }
            } else {
                $value = false;
            }
        }

        if ($value) {
            $ttl = $this->execute(['TTL', $key]);
            /*
            Когда оставшийся срок жизни меньше заданного порога, вернуть false, что спровоцирует
            перезапись кеша. Пока процесс идет, другие запросы будут получать данные из кеша с
            продленным ttl.
            Продлеваем только при чтении всего хеша. При чтении поля позволяем хешу умирать.
            Иначе получается редкий, неуловимый баг. Для проверки ttl перед чтением поля используй
            self::exists().
            */
            if (!$field && $ttl < self::THRESHOLD) {
                $this->execute(['EXPIRE', $key, $ttl + self::THRESHOLD]);
                $value = false;
            }
        }

        return $value;
    }

    /**
     * Запись строки в кеш. Строка - это наиболее популярный способ кеширования, поэтому никаких
     * приписок к имени метода.
     *
     * @param string $key
     * @param string $data
     * @param int $ttl время жизни кеша. 0 = пожизненный кеш
     * @return bool|null
     */
    public function write($key, $data, $ttl = 300)
    {
        if ($this->off) {
            return null;
        }

        if ($ttl)
            //$result = (bool)$this->execute(['SET', $key, $data, 'EX', $ttl]); //>= v2.6.12
            $result = (bool)$this->execute(['SETEX', $key, $ttl, $data]); // < v2.6.12
        else
            $result = (bool)$this->execute(['SET', $key, $data]); //пожизненный кеш

        return $result;
    }

    /**
     * Чтение строки из кеша
     * @param string $key
     * @return string|bool|null
     */
    public function read($key)
    {
        if ($this->off) {
            return null;
        }

        $ttlMsg = '';
        if ($value = $this->execute(['GET', $key])) {
            $ttl = $this->execute(['TTL', $key]);
            /*
            Когда оставшийся срок жизни меньше заданного порога, вернуть false, что спровоцирует
            перезапись кеша. Пока процесс идет, другие запросы будут получать данные из кеша с
            продленным ttl.
            */
            if ($ttl < self::THRESHOLD) {
                $this->execute(['EXPIRE', $key, $ttl + self::THRESHOLD]);
                $value = false;
            }
        }

        return $value;
    }

    /**
     * Десериалиализация данных
     * @param array|string $value данные из кеша
     * @return array тот же массив, но десериализованные данные
     */
    public function fullInfo($value)
    {
        //на входе возможно не массив. Вызов из self::read(), например.
        if (!is_array($value)) {
            return ($rlst = @unserialize($value)) ? $rlst : $value;
        }

        foreach ($value as &$v) {
            if (!$try = @unserialize($v)) {
                continue;
            }
            $v = $try;
        }

        return $value;
    }

    /**
     * Проверка существования ключа.
     * Донор: self::read()
     * @param string $key
     * @return bool|null
     */
    public function exists($key)
    {
        if ($this->off) {
            return null;
        }

        if ($result = (bool)$this->execute(['EXISTS', $key])) {
            $ttl = $this->execute(['TTL', $key]);
            /*
            Когда оставшийся срок жизни меньше заданного порога, вернуть false, что спровоцирует
            перезапись кеша. Пока процесс идет, другие запросы будут получать данные из кеша с
            продленным ttl.
            */
            if ($ttl < self::THRESHOLD) {
                $this->execute(['EXPIRE', $key, $ttl + self::THRESHOLD]);
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Удаление записи из кеша по ключу
     * Redis не признает удаление по ключам с wildcard (звездочкой). Зато поддерживает подобное
     * чтение, команда "keys some*". Расширяем возможности удаления. Если есть звездочки, тогда
     * сначала получаем список ключей, потом все их удаляем.
     * @param array|string $key
     * @return long количество удаленных ключей
     */
    public function delete($key)
    {
        if ($this->off) {
            return null;
        }

        if (strpos($key, '*') !== false) {
            $key = $this->scan($key);
        }

        $cnt = 0;
        foreach ((array)$key AS $k) {
            if ($this->execute(['DEL', $k])) {
                $cnt++;
            }
        }

        return $cnt;
    }

    /**
     * Шустрая альтернатива KEYS. Рекомендуется для использования на production
     * @see http://redis.io/commands/scan
     * @param string $pattern шаблон фильтра
     * @param int $cursor указатель в массиве сканирования
     * @return array список ключей
     */
    public function scan($pattern = '*', $cursor = 0)
    {
        if ($this->off) {
            return null;
        }

        $cmd = "SCAN {$cursor} COUNT 50";
        if ($pattern !== '*') {
            $cmd .= " MATCH {$pattern}";
        }

        $cmd = explode(' ', $cmd);

        $result = array();
   //     $hardStop = 0;
        do {
            $keys = $this->execute($cmd);
            //этот элемент содержит значение курсора. Для продолжения скана нужно сдвигать курсор в команде
            $cmd[1] = $keys[0];
            $result = array_merge($result, $keys[1]);
   //     } while ($keys[0] !== '0' && ++$hardStop < 10);
        } while ($keys[0] !== '0');

        return $result;
    }

    /**
     * Для монитора кеша. В шаблоне цветом выделяются ключи с истекающим сроком
     */
    public function getThreshold()
    {
        return self::THRESHOLD;
    }
}
