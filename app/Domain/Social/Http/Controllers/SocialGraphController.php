<?php

namespace App\Domain\Social\Http\Controllers;

use App\Domain\Social\Services\SocialGraphService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class SocialGraphController extends Controller
{
    public function __construct(private readonly SocialGraphService $service) {}

    public function artists(Request $request){return $this->service->artists($request);}
    public function publicArtist(Request $request,string $slug){return $this->service->publicArtist($request,$slug);}
    public function myArtists(Request $request){return $this->service->myArtists($request);}
    public function storeArtist(Request $request){return $this->service->storeArtist($request);}
    public function updateArtist(Request $request,int $id){return $this->service->updateArtist($request,$id);}
    public function publicEventArtists(string $slug){return $this->service->publicEventArtists($slug);}
    public function eventArtists(Request $request,int $eventId){return $this->service->eventArtists($request,$eventId);}
    public function attachArtist(Request $request,int $eventId){return $this->service->attachArtist($request,$eventId);}
    public function detachArtist(Request $request,int $eventId,int $artistId){return $this->service->detachArtist($request,$eventId,$artistId);}
    public function follow(Request $request){return $this->service->follow($request);}
    public function unfollow(Request $request){return $this->service->unfollow($request);}
    public function engagement(Request $request,int $eventId){return $this->service->engagement($request,$eventId);}
    public function preferences(Request $request){return $this->service->preferences($request);}
}
