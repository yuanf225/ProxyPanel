<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Models\Coupon;
use DB;
use Exception;
use Illuminate\Http\Request;
use Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Redirect;
use Response;

/**
 * 优惠券控制器
 *
 * Class CouponController
 *
 * @package App\Http\Controllers\Controller
 */
class CouponController extends Controller {
	// 优惠券列表
	public function couponList(Request $request) {
		$sn = $request->input('sn');
		$type = $request->input('type');
		$status = $request->input('status');

		$query = Coupon::query();

		if(isset($sn)){
			$query->where('sn', 'like', '%'.$sn.'%');
		}

		if(isset($type)){
			$query->whereType($type);
		}

		if(isset($status)){
			$query->whereStatus($status);
		}

		$view['couponList'] = $query->orderBy('id', 'desc')->paginate(15)->appends($request->except('page'));

		return Response::view('admin.coupon.couponList', $view);
	}

	// 添加商品
	public function addCoupon(Request $request) {
		if($request->isMethod('POST')){
			$this->validate($request, [
				'name'            => 'required',
				'sn'              => 'unique:coupon',
				'type'            => 'required|integer|between:1,3',
				'usage'           => 'required|integer|between:1,2',
				'num'             => 'required|integer|min:1',
				'amount'          => 'required_unless:type,2|numeric|min:0.01|nullable',
				'discount'        => 'required_if:type,2|numeric|between:1,9.9|nullable',
				'available_start' => 'required|date|before_or_equal:available_end',
				'available_end'   => 'required|date|after_or_equal:available_start',
			], [
				                'name.required'                   => '请填入卡券名称',
				                'type.required'                   => '请选择卡券类型',
				                'type.integer'                    => '卡券类型不合法，请重选',
				                'type.between'                    => '卡券类型不合法，请重选',
				                'usage.required'                  => '请选择卡券用途',
				                'usage.integer'                   => '卡券用途不合法，请重选',
				                'usage.between'                   => '卡券用途不合法，请重选',
				                'num.required'                    => '请填写卡券数量',
				                'num.integer'                     => '卡券数量不合法',
				                'num.min'                         => '卡券数量不合法，最小1',
				                'amount.required_unless'          => '请填入卡券面值',
				                'amount.numeric'                  => '卡券金额不合法',
				                'amount.min'                      => '卡券金额不合法，最小0.01',
				                'discount.required_if'            => '请填入卡券折扣',
				                'discount.numeric'                => '卡券折扣不合法',
				                'discount.between'                => '卡券折扣不合法，有效范围：1 ~ 9.9',
				                'available_start.required'        => '请填入有效期',
				                'available_start.date'            => '有效期不合法',
				                'available_start.before_or_equal' => '有效期不合法',
				                'available_end.required'          => '请填入有效期',
				                'available_end.date'              => '有效期不合法',
				                'available_end.after_or_equal'    => '有效期不合法'
			                ]);

			$type = $request->input('type');

			// 优惠卷LOGO
			if($request->hasFile('logo')){
				$file = $request->file('logo');
				$fileType = $file->getClientOriginalExtension();

				// 验证文件合法性
				if(!in_array($fileType, ['jpg', 'png', 'jpeg', 'bmp'])){
					return Redirect::back()->withInput()->withErrors('LOGO不合法');
				}

				$logoName = date('YmdHis').mt_rand(1000, 2000).'.'.$fileType;
				$move = $file->move(base_path().'/public/upload/image/', $logoName);
				$logo = $move? '/upload/image/'.$logoName : '';
			}else{
				$logo = '';
			}

			DB::beginTransaction();
			try{
				for($i = 0; $i < $request->input('num'); $i++){
					$obj = new Coupon();
					$obj->name = $request->input('name');
					$obj->sn = $request->input('sn')?: strtoupper(makeRandStr(8));
					$obj->logo = $logo;
					$obj->type = $type;
					$obj->usage = $request->input('usage');
					$obj->amount = $type == 2? 0 : $request->input('amount');
					$obj->discount = $type != 2? 0 : $request->input('discount');
					$obj->rule = $request->input('rule');
					$obj->available_start = strtotime(date('Y-m-d 00:00:00',
					                                       strtotime($request->input('available_start'))));
					$obj->available_end = strtotime(date('Y-m-d 23:59:59',
					                                     strtotime($request->input('available_end'))));
					$obj->status = 0;
					$obj->save();
				}

				DB::commit();

				return Redirect::back()->with('successMsg', '生成成功');
			}catch(Exception $e){
				DB::rollBack();

				Log::error('生成优惠券失败：'.$e->getMessage());

				return Redirect::back()->withInput()->withErrors('生成失败：'.$e->getMessage());
			}
		}else{
			return Response::view('admin.coupon.addCoupon');
		}
	}

	// 删除优惠券
	public function delCoupon(Request $request) {
		Coupon::query()->whereId($request->input('id'))->delete();

		return Response::json(['status' => 'success', 'data' => '', 'message' => '删除成功']);
	}

	// 导出卡券
	public function exportCoupon() {
		$voucherList = Coupon::type(1)->whereStatus(0)->get();
		$discountCouponList = Coupon::type(2)->whereStatus(0)->get();
		$refillList = Coupon::type(3)->whereStatus(0)->get();

		$filename = '卡券'.date('Ymd').'.xlsx';
		$spreadsheet = new Spreadsheet();
		$spreadsheet->getProperties()
		            ->setCreator('SSRPanel')
		            ->setLastModifiedBy('SSRPanel')
		            ->setTitle('邀请码')
		            ->setSubject('邀请码')
		            ->setDescription('')
		            ->setKeywords('')
		            ->setCategory('');

		// 抵用券
		$spreadsheet->setActiveSheetIndex(0);
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle('抵用券');
		$sheet->fromArray(['名称', '类型', '有效期', '券码', '金额（元）', '使用限制（元）'], null);
		foreach($voucherList as $k => $vo){
			$usage = $vo->usage == 1? '一次性' : '重复使用';
			$dateRange = date('Y-m-d', $vo->available_start).' ~ '.date('Y-m-d', $vo->available_end);
			$sheet->fromArray([$vo->name, $usage, $dateRange, $vo->sn, $vo->amount, $vo->rule], null, 'A'.($k + 2));
		}

		// 折扣券
		$spreadsheet->createSheet(1);
		$spreadsheet->setActiveSheetIndex(1);
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle('折扣券');
		$sheet->fromArray(['名称', '类型', '有效期', '券码', '折扣（折）', '使用限制（元）'], null);
		foreach($discountCouponList as $k => $vo){
			$usage = $vo->usage == 1? '一次性' : '重复使用';
			$dateRange = date('Y-m-d', $vo->available_start).' ~ '.date('Y-m-d', $vo->available_end);
			$sheet->fromArray([$vo->name, $usage, $dateRange, $vo->sn, $vo->discount, $vo->rule], null, 'A'.($k + 2));
		}

		// 充值券
		$spreadsheet->createSheet(2);
		$spreadsheet->setActiveSheetIndex(2);
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setTitle('充值券');
		$sheet->fromArray(['名称', '类型', '有效期', '券码', '金额（元）'], null);
		foreach($refillList as $k => $vo){
			$usage = '一次性';
			$dateRange = date('Y-m-d', $vo->available_start).' ~ '.date('Y-m-d', $vo->available_end);
			$sheet->fromArray([$vo->name, $usage, $dateRange, $vo->sn, $vo->amount], null, 'A'.($k + 2));
		}

		// 指针切换回第一个sheet
		$spreadsheet->setActiveSheetIndex(0);

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); // 输出07Excel文件
		//header('Content-Type:application/vnd.ms-excel'); // 输出Excel03版本文件
		header('Content-Disposition: attachment;filename="'.$filename.'"');
		header('Cache-Control: max-age=0');
		$writer = new Xlsx($spreadsheet);
		$writer->save('php://output');
	}
}
