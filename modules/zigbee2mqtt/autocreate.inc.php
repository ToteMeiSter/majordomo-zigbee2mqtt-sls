<?php
/**
 * zigbee2mqtt: авто-создание простых устройств MajorDoMo из zigbee-устройств.
 *
 * Выполняется в области метода zigbee2mqtt->autoCreateSimpleDevices($dev_id):
 * доступны $this и $dev_id. $dev_id==0 → обработать все устройства.
 *
 * Идемпотентно: повторный вызов не плодит дубли — объект переиспользуется по
 * devices.TITLE+TYPE, уже привязанные строки подписок пропускаются.
 *
 * Имена устройств: <friendly_name>_<Свойство> (Реле/Температура/...).
 * Чтение: LINKED_OBJECT/LINKED_PROPERTY (значения текут через processMessage2).
 * Управление (relay/уставка термостата): PATH_WRITE(/set)+COMMAND_VALUE+PAYLOAD
 * и addLinkedProperty — диспетчеризуется модулем devices через propertySetHandle.
 */

require_once(DIR_MODULES . 'devices/devices.class.php');

// helper объявляем разово (include может выполняться многократно)
if (!function_exists('z2m_get_or_create_object')) {
    function z2m_get_or_create_object($dm, $module_name, $type, $title, $loc, &$log, $img_rel)
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

// METRIKA → array(тип MajorDoMo, свойство, управляемое(1/0), метка)
$MAP = array(
    'state'        => array('relay', 'status', 1, 'Реле'),
    'state_left'   => array('relay', 'status', 1, 'Реле Л'),
    'state_right'  => array('relay', 'status', 1, 'Реле П'),
    'state_center' => array('relay', 'status', 1, 'Реле Ц'),
    'state_l1'     => array('relay', 'status', 1, 'Реле 1'),
    'state_l2'     => array('relay', 'status', 1, 'Реле 2'),
    'state_l3'     => array('relay', 'status', 1, 'Реле 3'),
    'state_l4'     => array('relay', 'status', 1, 'Реле 4'),
    'state_1'      => array('relay', 'status', 1, 'Реле 1'),
    'state_2'      => array('relay', 'status', 1, 'Реле 2'),
    'temperature'  => array('sensor_temp', 'value', 0, 'Температура'),
    'humidity'     => array('sensor_humidity', 'value', 0, 'Влажность'),
    'pressure'     => array('sensor_pressure', 'value', 0, 'Давление'),
    'voltage'      => array('sensor_voltage', 'value', 0, 'Напряжение'),
    'current'      => array('sensor_current', 'value', 0, 'Ток'),
    'power'        => array('sensor_power', 'value', 0, 'Мощность'),
    'energy'       => array('sensor_power', 'value', 0, 'Энергия'),
    'battery'      => array('sensor_percentage', 'value', 0, 'Батарея'),
    'contact'      => array('openclose', 'status', 0, 'Контакт'),
    'local_temperature'        => array('thermostat', 'value', 0, 'Термостат'),
    'current_heating_setpoint' => array('thermostat', 'currentTargetValue', 1, 'Термостат'),
);

$dm = new devices();

$where = "TITLE<>'' AND TITLE<>'bridge'";
if ((int)$dev_id > 0) $where .= " AND ID=" . (int)$dev_id;
$devs = SQLSelect("SELECT * FROM zigbee2mqtt_devices WHERE $where");

$log = array('devices' => 0, 'created' => 0, 'bound' => 0, 'skipped_no_room' => 0);

foreach ($devs as $dev) {
    $loc = (int)$dev['LOCATION_ID'];
    if ($loc <= 0) { $log['skipped_no_room']++; continue; }
    $log['devices']++;

    $fname = trim($dev['TITLE']);
    $model = trim($dev['SELECTTYPE']);

    // картинка модели
    $img_rel = '';
    if ($model && method_exists($this, 'ensureImage') && $this->ensureImage($model)) {
        $img_rel = 'templates/' . $this->name . '/img/' . $model . '.jpg';
    }

    $thermo_obj = '';
    $rows = SQLSelect("SELECT * FROM zigbee2mqtt WHERE DEV_ID=" . (int)$dev['ID'] . " ORDER BY ID");
    foreach ($rows as $row) {
        $m = $row['METRIKA'];
        if (!isset($MAP[$m])) continue;
        if (trim($row['LINKED_OBJECT']) != '') continue; // уже привязано
        list($type, $prop, $ctrl, $label) = $MAP[$m];

        // объект (термостат — единый на устройство)
        if ($type == 'thermostat') {
            if (!$thermo_obj) $thermo_obj = z2m_get_or_create_object($dm, $this->name, $type, $fname . '_Термостат', $loc, $log, $img_rel);
            $obj = $thermo_obj;
        } else {
            $obj = z2m_get_or_create_object($dm, $this->name, $type, $fname . '_' . $label, $loc, $log, $img_rel);
        }
        if (!$obj) continue;

        // привязка строки подписки
        $upd = array('ID' => $row['ID'], 'LINKED_OBJECT' => $obj, 'LINKED_PROPERTY' => $prop, 'DISP_FLAG' => 1);
        if ($ctrl) {
            $upd['PATH_WRITE']    = preg_replace('~/[^/]+$~', '/set', $row['PATH']);
            $upd['COMMAND_VALUE'] = $m;
            if ($type == 'relay') { $upd['PAYLOAD_ON'] = 'ON'; $upd['PAYLOAD_OFF'] = 'OFF'; }
        }
        SQLUpdate('zigbee2mqtt', $upd);
        if ($ctrl) addLinkedProperty($obj, $prop, $this->name);
        $log['bound']++;

        // протолкнуть текущее значение в объект
        $cur = $row['VALUE'];
        if ($cur === 'ON')  $cur = '1';
        if ($cur === 'OFF') $cur = '0';
        if ($cur !== '' && $cur !== null && $cur[0] !== '{' && $cur[0] !== '[') {
            setGlobal($obj . '.' . $prop, $cur, array($this->name => '0'));
        }
    }
}

if (function_exists('debmes')) debmes('autoCreateSimpleDevices: ' . json_encode($log), 'zigbee2mqtt');
