<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Models\Application; use App\Models\Establishment; use App\Models\Item; use App\Models\Profile; use App\Models\User; use App\Services\Admin\EcosystemService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class EcosystemController extends Controller {
 public function __construct(private readonly EcosystemService $service) {}
 public function publicSite():JsonResponse{return $this->service->publicSite();}
 public function dashboard(Request $request):JsonResponse{return $this->service->dashboard($request);}
 public function activity(Request $request):JsonResponse{return $this->service->activity($request);}
 public function users(Request $request):JsonResponse{return $this->service->users($request);}
 public function userDetail(Request $request,User $user):JsonResponse{return $this->service->userDetail($request,$user);}
 public function storeUser(Request $request):JsonResponse{return $this->service->storeUser($request);}
 public function updateUser(Request $request,User $user):JsonResponse{return $this->service->updateUser($request,$user);}
 public function destroyUser(Request $request,User $user):JsonResponse{return $this->service->destroyUser($request,$user);}
 public function setUserAccess(Request $request,User $user,Application $application):JsonResponse{return $this->service->setUserAccess($request,$user,$application);}
 public function removeUserAccess(Request $request,User $user,Application $application):JsonResponse{return $this->service->removeUserAccess($request,$user,$application);}
 public function profiles(Request $request):JsonResponse{return $this->service->profiles($request);}
 public function storeProfile(Request $request):JsonResponse{return $this->service->storeProfile($request);}
 public function updateProfile(Request $request,Profile $profile):JsonResponse{return $this->service->updateProfile($request,$profile);}
 public function establishments(Request $request):JsonResponse{return $this->service->establishments($request);}
 public function storeEstablishment(Request $request):JsonResponse{return $this->service->storeEstablishment($request);}
 public function updateEstablishment(Request $request,Establishment $establishment):JsonResponse{return $this->service->updateEstablishment($request,$establishment);}
 public function transferEstablishmentOwner(Request $request,Establishment $establishment):JsonResponse{return $this->service->transferEstablishmentOwner($request,$establishment);}
 public function destroyEstablishment(Request $request,Establishment $establishment):JsonResponse{return $this->service->destroyEstablishment($request,$establishment);}
 public function items(Request $request):JsonResponse{return $this->service->items($request);}
 public function storeItem(Request $request):JsonResponse{return $this->service->storeItem($request);}
 public function updateItem(Request $request,Item $item):JsonResponse{return $this->service->updateItem($request,$item);}
 public function destroyItem(Request $request,Item $item):JsonResponse{return $this->service->destroyItem($request,$item);}
 public function settings(Request $request):JsonResponse{return $this->service->settings($request);}
 public function updateSettings(Request $request):JsonResponse{return $this->service->updateSettings($request);}
 public function auditLogs(Request $request):JsonResponse{return $this->service->auditLogs($request);}
}
