<?php
/**
 * zigbee2mqtt: авто-создание простых устройств MajorDoMo из zigbee-устройств.
 *
 * Выполняется в области метода zigbee2mqtt->autoCreateSimpleDevices($dev_id):
 * доступны $this и $dev_id. $dev_id==0 → обработать все устройства.
 *
 * Принцип «одно zigbee-устройство = одно простое устройство с набором свойств»
 * (по аналогии с модулем Hisense). Измерения (давление/батарея/сигнал/мощность/
 * ток/напряжение/энергия) — доп. свойства главного объекта, а не отдельные карточки.
 * Исключение: многоклавишные выключатели — по одному реле на канал (независимое
 * управление). Тип главного объекта определяется по возможностям устройства:
 *   лампа(color/brightness)→rgb/dimmer, выключатель/розетка→relay,
 *   датчик t°/влажности→sensor_temphum/temp/humidity, контакт→openclose,
 *   термостат(TRV)→thermostat.
 *
 * Чтение — LINKED_OBJECT/PROPERTY (значения текут через processMessage2).
 * Управление — PATH_WRITE(/set)+COMMAND_VALUE+PAYLOAD + addLinkedProperty;
 * диспетчер — модуль devices через LINKED_MODULES→propertySetHandle.
 * Идемпотентно: объект по devices.TITLE+TYPE, привязанные строки пропускаются.
 */

require_once(DIR_MODULES . 'devices/devices.class.php');

if (!function_exists('z2m_obj')) {
    // создать/получить объект по TITLE+TYPE (идемпотентно), вернуть имя объекта
    function z2m_obj($dm, $type, $title, $loc, &$log, $img_rel)
    {
        $title = trim(mb_substr($title, 0, 90));
        if ($title === '') return '';
        $ex = SQLSelectOne("SELECT LINKED_OBJECT FROM devices WHERE TITLE='" . DBSafe($title) . "' AND TYPE='" . DBSafe($type) . "' AND LINKED_OBJECT!=''");
        if (!empty($ex['LINKED_OBJECT'])) return $ex['LINKED_OBJECT'];
        $dm->addDevice($type, array('TITLE' => $title, 'LOCATION_ID' => $loc));
        $nd = SQLSelectOne("SELECT LINKED_OBJECT FROM devices WHERE TITLE='" . DBSafe($title) . "' AND TYPE='" . DBSafe($type) . "' AND LINKED_OBJECT!='' ORDER BY ID DESC");
        $obj = isset($nd['LINKED_OBJECT']) ? $nd['LINKED_OBJECT'] : '';
        if ($obj) {
            $log['created']++;
            if ($img_rel) setGlobal($obj . '.image', $img_rel, array());
        }
        return $obj;
    }
}

if (!function_exists('z2m_bind')) {
    // привязать строку подписки к obj.prop; $ctrl=1 — добавить управление
    function z2m_bind($module_name, $row, $obj, $prop, $ctrl, &$log)
    {
        if (!$row || trim($row['LINKED_OBJECT']) != '') return; // нет строки или уже привязано
        $upd = array('ID' => $row['ID'], 'LINKED_OBJECT' => $obj, 'LINKED_PROPERTY' => $prop, 'DISP_FLAG' => 1);
        if ($ctrl) {
            $upd['PATH_WRITE']    = preg_replace('~/[^/]+$~', '/set', $row['PATH']);
            $upd['COMMAND_VALUE'] = $row['METRIKA'];
            if ($prop == 'status') { $upd['PAYLOAD_ON'] = 'ON'; $upd['PAYLOAD_OFF'] = 'OFF'; }
        }
        SQLUpdate('zigbee2mqtt', $upd);
        if ($ctrl) addLinkedProperty($obj, $prop, $module_name);
        $log['bound']++;
        $cur = $row['VALUE'];
        if ($cur === 'ON')  $cur = '1';
        if ($cur === 'OFF') $cur = '0';
        if ($cur !== '' && $cur !== null && $cur[0] !== '{' && $cur[0] !== '[') {
            setGlobal($obj . '.' . $prop, $cur, array($module_name => '0'));
        }
    }
}

// каналы реле -> суффикс имени (для многоклавишников)
$RELAY_SUFFIX = array(
    'state' => '', 'state_left' => ' Л', 'state_right' => ' П', 'state_center' => ' Ц',
    'state_l1' => ' 1', 'state_l2' => ' 2', 'state_l3' => ' 3', 'state_l4' => ' 4',
    'state_1' => ' 1', 'state_2' => ' 2',
);
$STATE_KEYS = array('state', 'state_left', 'state_right', 'state_center', 'state_l1', 'state_l2', 'state_l3', 'state_l4', 'state_1', 'state_2');
// доп. измерения -> имя свойства (read-only, вешаются на главный объект)
$EXTRA_MEAS = array('pressure' => 'pressure', 'battery' => 'battery', 'linkquality' => 'linkquality',
    'voltage' => 'voltage', 'current' => 'current', 'power' => 'power', 'energy' => 'energy');

$dm = new devices();

$where = "TITLE<>'' AND TITLE<>'bridge'";
if ((int)$dev_id > 0) $where .= " AND ID=" . (int)$dev_id;
$devs = SQLSelect("SELECT * FROM zigbee2mqtt_devices WHERE $where");

$log = array('devices' => 0, 'created' => 0, 'bound' => 0, 'skipped_no_room' => 0);

foreach ($devs as $dev) {
    $loc = (int)$dev['LOCATION_ID'];
    if ($loc <= 0) { $log['skipped_no_room']++; continue; }
    $fname = trim($dev['TITLE']);
    $model = trim($dev['SELECTTYPE']);

    $img_rel = '';
    if ($model && method_exists($this, 'ensureImage') && $this->ensureImage($model)) {
        $img_rel = 'templates/' . $this->name . '/img/' . $model . '.jpg';
    }

    $rows = SQLSelect("SELECT * FROM zigbee2mqtt WHERE DEV_ID=" . (int)$dev['ID'] . " ORDER BY ID");
    if (!$rows) continue;
    $byM = array(); $present = array();
    foreach ($rows as $r) { $m = $r['METRIKA']; $present[$m] = true; if (!isset($byM[$m])) $byM[$m] = $r; }

    // тип из справочника (bulb/switch/thermostat/sensor/remote) — для офлайн-классификации
    $dtype = '';
    $tr = SQLSelectOne("SELECT type FROM zigbee2mqtt_devices_list WHERE zigbeeModel='" . DBSafe($model) . "' AND type<>'' LIMIT 1");
    if ($tr) $dtype = strtolower($tr['type']);

    // возможности
    $stateKeys = array();
    foreach ($STATE_KEYS as $sk) if (isset($present[$sk])) $stateKeys[] = $sk;
    $hasColor   = isset($present['color']) || isset($present['color_temp']);
    $hasBright  = isset($present['brightness']);
    $hasThermo  = isset($present['local_temperature']) || isset($present['current_heating_setpoint']);
    $hasContact = isset($present['contact']);
    $hasTemp    = isset($present['temperature']);
    $hasHum     = isset($present['humidity']);
    $isLight    = ($dtype == 'bulb' || $dtype == 'light' || $hasColor || $hasBright);

    $log['devices']++;
    $primary = '';

    if ($isLight && $stateKeys) {
        // лампа: rgb (цвет) / dimmer (яркость) / relay (только вкл-выкл)
        $ltype = $hasColor ? 'rgb' : ($hasBright ? 'dimmer' : 'relay');
        $primary = z2m_obj($dm, $ltype, $fname, $loc, $log, $img_rel);
        if ($primary) {
            z2m_bind($this->name, $byM[$stateKeys[0]], $primary, 'status', 1, $log);
            if ($hasColor && isset($byM['color']))      z2m_bind($this->name, $byM['color'], $primary, 'color', 0, $log);
            if ($hasBright && isset($byM['brightness'])) z2m_bind($this->name, $byM['brightness'], $primary, ($ltype == 'dimmer' ? 'level' : 'brightness'), 1, $log);
        }
    } elseif ($hasThermo) {
        $primary = z2m_obj($dm, 'thermostat', $fname, $loc, $log, $img_rel);
        if ($primary) {
            z2m_bind($this->name, isset($byM['local_temperature']) ? $byM['local_temperature'] : null, $primary, 'value', 0, $log);
            z2m_bind($this->name, isset($byM['current_heating_setpoint']) ? $byM['current_heating_setpoint'] : null, $primary, 'currentTargetValue', 1, $log);
        }
    } elseif ($hasContact) {
        $primary = z2m_obj($dm, 'openclose', $fname, $loc, $log, $img_rel);
        if ($primary) z2m_bind($this->name, $byM['contact'], $primary, 'status', 0, $log);
    } elseif ($stateKeys) {
        // выключатель/розетка: реле по каналам
        $multi = count($stateKeys) > 1;
        $first = '';
        foreach ($stateKeys as $sk) {
            $title = $multi ? ($fname . '_Реле' . $RELAY_SUFFIX[$sk]) : $fname;
            $obj = z2m_obj($dm, 'relay', $title, $loc, $log, $img_rel);
            if (!$obj) continue;
            z2m_bind($this->name, $byM[$sk], $obj, 'status', 1, $log);
            if (!$first) $first = $obj;
        }
        $primary = $first; // доп. измерения (мощность/батарея/сигнал) — на первое реле
    } elseif ($hasTemp || $hasHum) {
        $stype = ($hasTemp && $hasHum) ? 'sensor_temphum' : ($hasTemp ? 'sensor_temp' : 'sensor_humidity');
        $primary = z2m_obj($dm, $stype, $fname, $loc, $log, $img_rel);
        if ($primary) {
            if ($hasTemp) z2m_bind($this->name, $byM['temperature'], $primary, 'value', 0, $log);
            if ($hasHum)  z2m_bind($this->name, $byM['humidity'], $primary, ($stype == 'sensor_humidity' ? 'value' : 'valueHumidity'), 0, $log);
        }
    } elseif (isset($present['pressure'])) {
        $primary = z2m_obj($dm, 'sensor_pressure', $fname, $loc, $log, $img_rel);
        if ($primary) z2m_bind($this->name, $byM['pressure'], $primary, 'value', 0, $log);
    } else {
        continue; // кнопки/пульты без измеряемых/управляемых ядровых полей — пропуск
    }

    if (!$primary) continue;

    // доп. измерения на главный объект (read-only); уже привязанные строки z2m_bind пропустит
    foreach ($EXTRA_MEAS as $mk => $prop) {
        if (isset($byM[$mk])) z2m_bind($this->name, $byM[$mk], $primary, $prop, 0, $log);
    }
}

if (function_exists('debmes')) debmes('z2m autoCreateSimpleDevices: ' . json_encode($log), 'zigbee2mqtt');
