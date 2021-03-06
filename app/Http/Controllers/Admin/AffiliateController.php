<?php

namespace App\Http\Controllers\Admin;

use App\Components\Helpers;
use App\Http\Controllers\Controller;
use App\Http\Models\ReferralApply;
use App\Http\Models\ReferralLog;
use Illuminate\Http\Request;
use Response;

/**
 * 推广控制器
 *
 * Class AffiliateController
 *
 * @package App\Http\Controllers\Controller
 */
class AffiliateController extends Controller {
	protected static $systemConfig;

	function __construct() {
		self::$systemConfig = Helpers::systemConfig();
	}

	// 提现申请列表
	public function affiliateList(Request $request) {
		$email = $request->input('email');
		$status = $request->input('status');

		$query = ReferralApply::with('user');
		if(isset($email)){
			$query->whereHas('user', function($q) use ($email) {
				$q->where('email', 'like', '%'.$email.'%');
			});
		}

		if($status){
			$query->whereStatus($status);
		}

		$view['applyList'] = $query->orderBy('id', 'desc')->paginate(15)->appends($request->except('page'));

		return Response::view('admin.affiliate.affiliateList', $view);
	}

	// 提现申请详情
	public function affiliateDetail(Request $request) {
		$id = $request->input('id');

		$list = null;
		$apply = ReferralApply::query()->with(['user'])->whereId($id)->first();
		if($apply && $apply->link_logs){
			$link_logs = explode(',', $apply->link_logs);
			$list = ReferralLog::query()
			                   ->with(['user', 'order.goods'])
			                   ->whereIn('id', $link_logs)
			                   ->paginate(15)
			                   ->appends($request->except('page'));
		}

		$view['info'] = $apply;
		$view['list'] = $list;

		return Response::view('admin.affiliate.affiliateDetail', $view);
	}

	// 设置提现申请状态
	public function setAffiliateStatus(Request $request) {
		$id = $request->input('id');
		$status = $request->input('status');

		$ret = ReferralApply::query()->whereId($id)->update(['status' => $status]);
		if($ret){
			// 审核申请的时候将关联的
			$referralApply = ReferralApply::query()->whereId($id)->first();
			$log_ids = explode(',', $referralApply->link_logs);
			if($referralApply && $status == 1){
				ReferralLog::query()->whereIn('id', $log_ids)->update(['status' => 1]);
			}elseif($referralApply && $status == 2){
				ReferralLog::query()->whereIn('id', $log_ids)->update(['status' => 2]);
			}
		}

		return Response::json(['status' => 'success', 'data' => '', 'message' => '操作成功']);
	}

	// 用户返利流水记录
	public function userRebateList(Request $request) {
		$email = $request->input('email');
		$ref_email = $request->input('ref_email');
		$status = $request->input('status');

		$query = ReferralLog::query()->with(['user', 'order'])->orderBy('status', 'asc')->orderBy('id', 'desc');

		if(isset($email)){
			$query->whereHas('user', function($q) use ($email) {
				$q->where('email', 'like', '%'.$email.'%');
			});
		}

		if(isset($ref_email)){
			$query->whereHas('ref_user', function($q) use ($ref_email) {
				$q->where('email', 'like', '%'.$ref_email.'%');
			});
		}

		if(isset($status)){
			$query->whereStatus($status);
		}

		$view['list'] = $query->paginate(15)->appends($request->except('page'));

		return Response::view('admin.affiliate.userRebateList', $view);
	}
}
