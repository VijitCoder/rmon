<?php
/**
 * Монитор кеша Redis
 * Идея постоянно в развитии. Реализую хотя бы что-то.
 *
 * Обращение:
 *  /rmon - страница собирается вместе с текущим списком ключей
 *  /rmon/?light - только шапка, данных нет. Их можно запросить ajax-ом (например уточнив фильтр)
 */
class RedismonitorController extends СController
{
    public $defaultAction='index';

    protected function beforeAction($action)
    {
        //Контроль доступа. После переноса функционала в админку это можно удалить.
        if (!in_array(strtolower(Yii::app()->user->name), ['vijit', 'debuger'])) {
            throw new CHttpException(403);
        }

        if ($action->id != 'index') {
            header('Content-Type: application/json');
            //header('Content-type: text/html; charset=utf-8'); //DBG

            //Отключаем выдачу логов. Иначе она цепляется в конец json-ответа.
            if (Yii::app()->hasComponent('log')) {
                foreach (Yii::app()->log->routes as $route) {
                $route->enabled = false;
            }
        }

        return true;
    }

    /**
     * Список при сборке страницы
     */
    public function actionIndex()
    {
        if (Yii::app()->redis->off) {
            $data['off'] = true;
        } elseif (isset($_GET['light'])) {
            $data = array();
        } else {
            $data = RedisMonService::keysList();
        }

        $this->pageTitle = 'Монитор кеша Redis';
        $this->render('//redismon', $data);
    }

    /**
     * Обновление списка. Возможно с указанием фильтра
     * (ajax)GET
     * - key фильтр ключа
     * - ttl фильтр ttl
     * Параметры принимаются непосредственно в рабочих методах.
     * @return json(['keys', 'ttls'])
     */
    public function actionUpdate()
    {
        echo RedisMonService::keysList();
    }

    /**
     * Получение содержимого кеша по заданному ключу
     * (ajax)GET['key']
     * @return string
     */
    public function actionInfo()
    {
        echo RedisMonService::info();
    }

    /**
     * Новое значение TTL кешу по заданному ключу и времени жизни
     * (ajax)POST['key']
     * @return int|string в случае ошибок
     */
    public function actionExpire()
    {
        echo RedisMonService::expire();
    }

    /**
     * Удаление кеша по заданному ключу или маске ключа
     * (ajax)POST['key']
     * @return string
     */
    public function actionDelete()
    {
        echo RedisMonService::delete();
    }
}
