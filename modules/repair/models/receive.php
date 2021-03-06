<?php
/**
 * @filesource modules/repair/models/receive.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Repair\Receive;

use \Kotchasan\Http\Request;
use \Gcms\Login;
use \Kotchasan\Language;
use \Kotchasan\Database\Sql;
use \Kotchasan\Text;

/**
 * เพิ่ม-แก้ไข ใบรับงาน
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

  /**
   * อ่านข้อมูลรายการที่เลือก
   * ถ้า $id = 0 หมายถึงรายการใหม่
   *
   * @param int $id ID
   * @return object|null คืนค่าข้อมูล object ไม่พบคืนค่า null
   */
  public static function get($id)
  {
    if (empty($id)) {
      // ใหม่
      return (object)array(
          'name' => '',
          'phone' => '',
          'address' => '',
          'provinceID' => self::$request->cookie('repair_provinceID', 102)->number(),
          'zipcode' => self::$request->cookie('repair_zipcode', 10000)->number(),
          'user_status' => 0,
          'customer_id' => 0,
          'equipment' => '',
          'serial' => '',
          'inventory_id' => 0,
          'job_description' => '',
          'create_date' => date('Y-m-d H:i:s'),
          'appointment_date' => date('Y-m-d'),
          'appraiser' => '',
          'id' => 0,
          'comment' => '',
          'status_id' => 0
      );
    } else {
      // แก้ไข
      $model = new static;
      $q1 = $model->db()->createQuery()
        ->select('repair_id', Sql::MAX('id', 'max_id'))
        ->from('repair_status')
        ->groupBy('repair_id');
      return $model->db()->createQuery()
          ->from('repair R')
          ->join(array($q1, 'T'), 'INNER', array('T.repair_id', 'R.id'))
          ->join('repair_status S', 'LEFT', array('S.id', 'T.max_id'))
          ->join('inventory V', 'LEFT', array('V.id', 'R.inventory_id'))
          ->join('user U', 'LEFT', array('U.id', 'R.customer_id'))
          ->where(array('R.id', $id))
          ->first('R.*', 'U.name', 'U.phone', 'U.address', 'U.zipcode', 'U.provinceID', 'U.status user_status', 'V.equipment', 'V.serial', 'S.status', 'S.comment', 'S.cost', 'S.operator_id', 'S.id status_id');
    }
  }

  /**
   * บันทึกค่าจากฟอร์ม
   *
   * @param Request $request
   */
  public function submit(Request $request)
  {
    $ret = array();
    // session, token, can_received_repair, ไม่ใช่สมาชิกตัวอย่าง
    if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
      if (Login::checkPermission($login, 'can_received_repair') && Login::notDemoMode($login)) {
        // รับค่าจากการ POST
        $user = array(
          'name' => $request->post('name')->topic(),
          'phone' => $request->post('phone')->topic(),
          'address' => $request->post('address')->topic(),
          'provinceID' => $request->post('provinceID')->number(),
          'zipcode' => $request->post('zipcode')->number(),
        );
        $inventory = array(
          'equipment' => $request->post('equipment')->topic(),
          'serial' => $request->post('serial')->topic(),
        );
        $repair = array(
          'job_description' => $request->post('job_description')->textarea(),
          'create_date' => $request->post('create_date')->date(),
          'appointment_date' => $request->post('appointment_date')->date(),
          'appraiser' => $request->post('appraiser')->toDouble(),
          'customer_id' => $request->post('customer_id')->toInt(),
          'inventory_id' => $request->post('inventory_id')->toInt(),
        );
        $log = array(
          'member_id' => $login['id'],
          'comment' => $request->post('comment')->topic()
        );
        // ตรวจสอบรายการที่เลือก
        $index = self::get($request->post('id')->toInt());
        if ($index) {
          // name
          if (empty($user['name'])) {
            $ret['ret_name'] = 'Please fill in';
          }
          // equipment
          if (empty($inventory['equipment'])) {
            $ret['ret_equipment'] = 'this';
          }
          if (empty($ret)) {
            if ($repair['customer_id'] == 0) {
              // ลงทะเบียนสมาชิกใหม่
              $user = \Index\Register\Model::execute($this, $user);
              // customer_id
              $repair['customer_id'] = $user['id'];
            } elseif ($index->user_status == 0) {
              // แก้ไขข้อมูลลูกค้า ถ้าเป็นสมาชิกทั่วไป
              $this->db()->update($this->getTableName('user'), $repair['customer_id'], $user);
            }
            // ตรวจสอบรายการพัสดุเดิม
            $table = $this->getTableName('inventory');
            $search = $this->db()->first($table, array(
              array('equipment', $inventory['equipment']),
              array('serial', $inventory['serial']),
            ));
            if (!$search) {
              // บันทึกพัสดุรายการใหม่
              $inventory['create_date'] = time();
              $repair['inventory_id'] = $this->db()->insert($table, $inventory);
            } else {
              // มีพัสดุเดิมอยู่ก่อนแล้ว
              $repair['inventory_id'] = $search->id;
            }
            // ตาราง repair
            $table = $this->getTableName('repair');
            // job_id
            if ($index->id == 0) {
              // สุ่ม job_id 10 หลัก
              $repair['job_id'] = Text::rndname(10, 'ABCDEFGHKMNPQRSTUVWXYZ0123456789');
              // ตรวจสอบ job_id ซ้ำ
              while ($this->db()->first($table, array('job_id', $repair['job_id']))) {
                $repair['job_id'] = Text::rndname(10, 'ABCDEFGHKMNPQRSTUVWXYZ0123456789');
              }
              $repair['create_date'] = date('Y-m-d H:i:s');
              $log['create_date'] = $repair['create_date'];
              // บันทึกรายการแจ้งซ่อม
              $log['repair_id'] = $this->db()->insert($table, $repair);
              $log['status'] = isset(self::$cfg->repair_first_status) ? self::$cfg->repair_first_status : 1;
            } else {
              // แก้ไขรายการแจ้งซ่อม
              $this->db()->update($table, $index->id, $repair);
              $log['repair_id'] = $index->id;
              $repair['job_id'] = $index->job_id;
            }
            // บันทึกประวัติการทำรายการ
            $table = $this->getTableName('repair_status');
            if ($index->status_id == 0) {
              $repair['id'] = $this->db()->insert($table, $log);
            } else {
              $this->db()->update($table, $index->status_id, $log);
            }
            // คืนค่า
            $ret['alert'] = Language::get('Saved successfully');
            $ret['location'] = 'index.php?module=repair-setup';
            if ($request->post('print')->toString() == 1) {
              $ret['open'] = WEB_URL.'modules/repair/print.php?id='.$repair['job_id'];
            }
            // clear
            $request->removeToken();
            // save cookie
            setcookie('repair_provinceID', $user['provinceID'], time() + 3600 * 24 * 365, '/');
            setcookie('repair_zipcode', $user['zipcode'], time() + 3600 * 24 * 365, '/');
          }
        } else {
          // ไม่พบรายการที่แก้ไข
          $ret['alert'] = Language::get('Sorry, Item not found It&#39;s may be deleted');
        }
      }
    }
    if (empty($ret)) {
      $ret['alert'] = Language::get('Unable to complete the transaction');
    }
    // คืนค่าเป็น JSON
    echo json_encode($ret);
  }
}