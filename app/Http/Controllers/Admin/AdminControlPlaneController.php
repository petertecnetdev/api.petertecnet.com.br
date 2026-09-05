<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\EcosystemSetting;
use App\Services\Admin\AdminControlPlaneService;
use Illuminate\Http\Request;
class AdminControlPlaneController extends Controller {
 public function __construct(private readonly AdminControlPlaneService $service) {}
 public function capabilities(){return $this->service->capabilities();}
 public function featureFlags(){return $this->service->featureFlags();}
 public function saveFeatureFlags(Request $request){return $this->service->saveFeatureFlags($request);}
 public function savedViews(Request $request){return $this->service->savedViews($request);}
 public function saveView(Request $request){return $this->service->saveView($request);}
 public function deleteView(Request $request,EcosystemSetting $setting){return $this->service->deleteView($request,$setting);}
 public function notificationCampaigns(Request $request){return $this->service->notificationCampaigns($request);}
 public function storeNotificationCampaign(Request $request){return $this->service->storeNotificationCampaign($request);}
 public function moderation(Request $request){return $this->service->moderation($request);}
 public function updateModeration(Request $request,int $report){return $this->service->updateModeration($request,$report);}
 public function trash(Request $request){return $this->service->trash($request);}
 public function restoreTrash(Request $request,string $resource,int $id){return $this->service->restoreTrash($request,$resource,$id);}
 public function export(Request $request,string $resource){return $this->service->export($request,$resource);}
 public function import(Request $request,string $resource){return $this->service->import($request,$resource);}
}
