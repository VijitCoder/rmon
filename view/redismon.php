<?php
/**
 * монитор кеша Redis
 */
?>
<div id="accordion">
    <h3>Из списка ключей перманентно удаляются:</h3>
    <ul>
        <li> - <b>32-значный код</b> - это md5-хеш поисковой фразы. Сам-то он может и полезен, но найти нужный ключ
        среди десятков ему подобных нереально.</li>
        <li> - <b>sess:[\w-]{32}</b> - сессионная инфа мобильного приложения. Не нужна для отладки/мониторинга.</li>
    </ul>

    <h3>Мануал</h3>
    <div>
    <p><ul>
        <li> - <b>кнопка "↺"</b> - ajax обновление списка. С учетом фильтра. Если фильтры не заданы,
        получим весь список. Если время жизни кеша (ttl) не больше 120 секунд, строка подсвечена красным.
        Такие кеши будут перезаписаны при следующем обращении юзера.</li>

        <li> - <b>фильтр ttl</b> фильтрует больше/меньше заданного значения и значение в промежутке.
        Валидатор принимает только целые, неотрицательные числа, и три знака "&gt;, &lt;, -".
        Примеры: "<i>&gt;119</i>", "<i>120-300</i>", "<i>&lt;1000</i>". По точному значению ttl
        не фильтруем, потому что это лишено практического смысла. Ошибочный фильтр просто игнорируется,
        сообщений не будет.</li>

        <li> - <b>фильтр имени</b>. типа <i>*Author*</i> - все ключи содержащие "Author".
        <i>Blog*</i> - все ключи, начинающиеся на "Blog". Возможна даже такая маска: <i>l*st.Blog:*0</i>,
        найдет "list.Blog:140", "lost.Blog:0" и т.п.</li>

        <li> - <b>поле '[ x ]'</b> - удаление кеша.</li>

        <li> - <b>клик на число</b> - задать ttl. Ввели в появившемся поле число, нажали Enter - ждем
        результат. Поле ввода исчезнет, новое значение пропишется. В случае ошибки будет сообщение.
        Нажатие Esc отменит ввод нового значения.</li>

        <li> - <b>клик по ключу</b> - запрос содержимого. Вернет сырые или форматированные данные
        (в зависимости от настройки), <b>при этом актулизируется значение ttl</b> для выбранной строки.</li>

        <li> - <b>удаление</b> кешей по маске ключа. В поле "<i>имя ключа</i>" пишем маску, справа от нее - [x]. Это кнопочка удаления.
        В результате будет возвращено количество удаленных записей.</li>
    </ul></p>
    </div>

</div>

<fieldset class='rc-options'>
    <legend>Настройки</legend>
    <label><input id='rawdata' type='checkbox'> сырые данные</label><br>
    <label><input id='keepdata' type='checkbox' checked> сохранять загруженную инфу</label><br>
    <label><input id='tgl-empty' type='checkbox'> скрыть пустые строки</label>
</fieldset>

<fieldset class='rc-options'>
    <legend>Управление</legend>
    <input id='bn-flush' type='button' value='flushdb'><br>
    <input id='bn-off' type='button' value = 'redis off'><br>
    <input id='bn-on' type='button' value = 'redis on'><br>
</fieldset>

<div id='redis-off' class="user-flash uf-info clear"
    <?php if (!isset($off)) { echo 'style="display:none;"'; }?>
>Redis отключен настройкой.</div>


<div id='head-spacer' class='clear'></div>
<div id='rc-head'>
   <table id='rc-list-head'>
        <col width='25px'>
        <col width='50px'>
        <col width='260px'>
        <col width='850px'>
        <tr>
            <td></td>
            <td>TTL</td>
            <td>Имя ключа</td>
            <td>Содержимое кеша</td>
        </tr>
        <tr>
            <td id='rc-update'>↺</td>
            <td><input id='flt-ttl' type='text' size=4 value='>119'></td>
            <td><input id='flt-key' type='text' size=20><span id='ch-del-mask'>[ x ]</span></td>
            <td id='flt-info'></td>
        </tr>
     </table>
</div>

<table id='rc-list-body'>
    <col width='25px'>
    <col width='50px'>
    <col width='260px'>
    <col width='850px'>
     <?php
    if (isset($keys)) {
        $threshold = Yii::app()->rmon->threshold;
        foreach ($keys as $key) {
            $cls = $ttls[$key] < $threshold ? 'uf-err' : '';
        ?>
            <tr class='<?php echo $cls;?>'>
                <td class='ch-del'>[ x ]</td>
                <td class='ch-ttl'><?php echo $ttls[$key];?></td>
                <td class='ch-key'><?php echo $key;?></td>
                <td class='ch-info'></td>
            </tr>
    <?php }} ?>
</table>

<script>
$(function(){
    //Ниже этого значения считать кеш устаревшим. Выделяем цветом
    var threshold = <?php echo (int)Yii::app()->rmon->threshold; ?>

    /**
     * фиксируем шапку кверху
     */
    // если на сайте отображается панель пользователя
    var uh = $('.user-banner').height();
    if (uh == undefined) uh = 0;
    var headOffset = $('#head-spacer').offset().top - uh;
    var topOffset = uh;
    var w = $('.content-padded').width();
    $(window).scroll(function() {
        var top = $(document).scrollTop();
        if (top < headOffset) {
            $('#head-spacer').height('auto');
            $('#rc-head').css({top: 0, position: 'relative'});
        } else {
            $('#head-spacer').height($('#rc-head').height());
            $('#rc-head').css({top: topOffset, position: 'fixed', width: w, "background-color":"#dfeffc"});
        }
    });

    /**
     * подсветка строк таблицы при перемещении над ними мыша
     */
    $('#rc-list-body')
        .on('mouseenter', 'tr', function() {
            $(this).addClass('uf-info');
        })
        .on('mouseleave', 'tr', function() {
            $(this).removeClass('uf-info');
        });

    /**
     * Поля фильтров. Реакция на клавиши
     */
    $('#flt-key, #flt-ttl').keyup(function(evObj){
        if (evObj.which === 13) {        // "Enter"
            $('#rc-update').click();
        } else if (evObj.which === 27) { // "Esc"
            $(evObj.target).val('');
        }
    });

    /**
     * запрос ключей по фильтру
     */
    $('#rc-update').click(function() {
        var flt_ttl = $('#flt-ttl').prop('value');
        var flt_key = $('#flt-key').prop('value');
        var flt_info = $('#flt-info').html('в процессе..');

        $('#tgl-empty').prop('checked', false);
        $('#rc-list-body tr').remove();

        $.ajax({
            type: 'GET',
            url: '/rmon/update?key=' + flt_key + '&ttl=' + flt_ttl,
            complete: function (data, status) {
                //прокручиваем в начало списка, если нужно. Значение получено ранее, перед фиксацией шапки.
                if ($(document).scrollTop() > headOffset) {
                    $(document).scrollTop(headOffset);
                }
                if (status == 'success') {
                    var rslt = $.parseJSON(data.responseText);
                    if (rslt.keys.length == 0) {
                        $(flt_info).html('нет совпадений');
                    } else {
                        $(flt_info).html('');
                        var key, cls, ttl;
                        var table = $('#rc-list-body');
                        for (var i = 0; i < rslt.keys.length; i++) {
                            key = rslt.keys[i];
                            ttl = Number(rslt.ttls[key]);
                            cls = '';
                            if (ttl == -1) {
                                ttl = '&#8734';
                            } else if (ttl < threshold) {
                                cls = 'uf-err';
                            }
                            $(table).append(
                                "<tr class='" + cls + "'>" +
                                "<td class='ch-del'>[ x ]</td>" +
                                "<td class='ch-ttl'>" + ttl + "</td>" +
                                "<td class='ch-key'>" + key + "</td>" +
                                "<td class='ch-info'></td>" +
                                '</tr>'
                            );
                        }
                    }
                } else {
                    $(flt_info).html('<span class="uf-err">Ошибка обновления данных</span>');
                }
            }
        });
    });

    /**
     * Удаление одного кеша по ключу
     */
    $(document).on('click', '.ch-del', function() {
 //   $('.ch-del').on('click', function() { //так не работает для новых строк
        var tr = $(this).parent('tr');
        var chKey = $('.ch-key',tr).text();
        if (confirm('Удалить кеш с ключом "' + chKey + '"?')) {
            $.ajax({
                type: 'POST',
                data: {key:chKey,},
                dataType:'html',
                url: '/rmon/delete',
                complete: function (data, status) {
                    if (status == 'success' && data.responseText == '1') {
                        tr.remove();
                    } else {
                        tr.addClass('uf-err');
                        $('.ch-info', tr).text('Ошибка удаления. ' + data.responseText);
                        //alert('Ошибка удаления. ' + data.responseText);
                    }
                }
            });
        }
    });

    /**
     * Удаление кешей по маске ключа
     */
    $(document).on('click', '#ch-del-mask', function() {
        var key = $('#flt-key').val();
        if (confirm('Удалить кеши по маске ключа "' + key + '"?')) {
            $.ajax({
                type: 'POST',
                data: {key:key,},
                dataType:'html',
                url: '/rmon/delete',
                complete: function (data, status) {
                    if (status == 'success' && Number(data.responseText)) {
                        $('#flt-info').html('<span class="uf-info">Ключ: ' + key
                            + '. Удалено записей: ' + data.responseText + '</span>');
                        $('#flt-key').val('');
                    } else if (data.responseText === '0') {
                        $('#flt-info').html('<span class="uf-warn">Ключ: ' + key
                            + '. Записи не найдены.</span>');
                        $('#flt-key').val('');
                    } else {
                        $('#flt-info').html('<span class="uf-err">' + data.responseText + '</span>');
                    }
                }
            });
        }
    });

    /**
     * Задаем новый ttl
     */
    $(document).on('click', '.ch-ttl', function() {
        var owner = $(this);
        owner.html('<input id="set-ttl" type="text" size=3 value="' + owner.text() + '">');

        var _setter = $("#set-ttl").click(function(ev){  ev.stopPropagation(); }).focus().select();

        _setter.blur(function() {
                var $this = $(this);
                $this.parent().text($this.prop('value'));
                $this.remove();
            });

        _setter.keyup(function(evObj) {
            if (evObj.which === 13) {        // "Enter"
                _updateTtl(_setter.val());
            } else if (evObj.which === 27) { // "Esc"
                _setter.blur();
            }
        });

        function _updateTtl(t) {
            $.ajax({
                type: 'POST',
                data: {ttl:t, key:owner.next('.ch-key').text(),},
                url: '/rmon/expire',
                dataType:'html',
                complete: function (data, status) {
                    var rslt = data.responseText;
                    //@see http://learn.javascript.ru/number#проверка-на-число-для-всех-типов
                    if (status == 'success' && !isNaN(parseFloat(rslt)) && isFinite(rslt)) {
                        owner.html(rslt);
                        if (rslt < threshold) {
                            owner.parent().addClass('uf-err');
                        } else {
                            owner.parent().removeClass('uf-err');
                        }
                    } else {
                        _setter.blur();
                        owner.text('??').parent().addClass('uf-err');
                        owner.siblings('.ch-info').text('Ошибка обновления ttl: ' + rslt);
                    }
                }
            });
        };
    });

    // Данные из кеша по ключу

    //ячейка, куда последний загрузили данные кеша. При соответствующей настройке, она очищается
    //с очередным запросом данных кеша.
    var tdInfo;

    $(document).on('click', '.ch-key', function() {
        var tr = $(this).parent('tr');

        var raw = $('#rawdata').prop('checked') ? '&raw' : '';
        if (!$('#keepdata').prop('checked') && typeof(tdInfo) != 'undefined') {
            $(tdInfo).empty();
        };
        tdInfo = $('.ch-info', tr);

        $.ajax({
            type: 'GET',
            dataType: 'json',
            url: '/rmon/info?key=' + $(this).text() + raw,
            complete: function (data, status) {
                if (status == 'success') {
                 //   var rslt = $.parseJSON(data.responseText);
                    var rslt = data.responseJSON;
                    var ttl = rslt.ttl;

                    if (ttl == '-1') {
                        ttl = '&#8734;';
                        tr.removeClass('uf-err');
                   } else if (ttl < threshold) {
                        tr.addClass('uf-err');
                    } else {
                        tr.removeClass('uf-err');
                    }

                    $('.ch-ttl', tr).html(ttl);

                    $(tdInfo).html('<div class="cache-info"><pre>Тип: <b>' + rslt.type + "</b>\n" + rslt.data + '</pre></div>');
                } else {
                    $(tdInfo).html('Ошибка получения данных.');
                    tr.addClass('uf-err');
                }
            }
        });
    });

    /**
     * при сбросе флага - очищать колонку с инфой кешей
     */
    $('#keepdata').click(function() {
        if (!$(this).prop('checked')) {
            $('.ch-info').empty();
        }
    });

    /**
     * Взмах флагом - переключение видимости пустых строк (без инфы кеша)
     */
    $('#tgl-empty').click(function() {
        if (!$(this).prop('checked')) {
            $('#rc-list-body tr').show();
        } else {
            $('#rc-list-body tr').each(function() {
                if ($('.ch-info', this).html() == '') {
                   $(this).hide();
                }
            });
        }
    });


    /**
     * Управление
     */
    // Сброс базы
    $('#bn-flush').click(function() {
        $.ajax({
            type: 'GET',
            dataType: 'html',
            url: '/rmon/control?op=flush',
            complete: function (data, status) {
                if (status == 'success' && data.responseText == '1') {
                     $('#flt-info').html('<span class="uf-info">База очищена</span>');
                     $('#rc-list-body tr').remove();
                } else {
                    $('#flt-info').html('<span class="uf-err">Ошибка очистки базы</span>');
                }
            },
        });
    });

    // Вкл/откл кешера
    $('#bn-off, #bn-on').click(function(ev) {
        var op = ev.target.id.substr(3);

        $.ajax({
            type: 'GET',
            dataType: 'html',
            url: '/rmon/control?op=' + op,
            complete: function (data) {
                if (data.responseText == '1') {
                    op == 'off' ? $('#redis-off').show() : $('#redis-off').hide();
                    $('#flt-info').empty();
                } else {
                    $('#flt-info').html('<span class="uf-err">' + data.responseText + '</span>');
                }
            },
            error: function (data, status, xhr) {
                $('#flt-info').html('<span class="uf-err">Не удалось переключить состояние кешера</span>');
            },
        });
    });
});
</script>
