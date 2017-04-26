<?php

namespace App\Http\Controllers;

use View;
use Utils;
use Option;
use Storage;
use Session;
use App\Models\User;
use App\Models\Closet;
use App\Models\Player;
use App\Models\Texture;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\PrettyPageException;
use App\Services\Repositories\UserRepository;

class SkinlibController extends Controller
{
    private $user = null;

    public function __construct(UserRepository $users)
    {
        // Try to load user by uid stored in session.
        // If there is no uid stored in session or the uid is invalid
        // it will return a null value.
        $this->user = $users->get(session('uid'));
    }

    public function index(Request $request)
    {
        $filter  = $request->input('filter', 'skin');
        $sort    = $request->input('sort', 'time');
        $uid     = intval($request->input('uid', 0));
        $page    = $request->input('page', 1) <= 0 ? 1 : $request->input('page', 1);

        $sort_by = ($sort == "time") ? "upload_at" : $sort;

        if ($filter == "skin") {
            $textures = Texture::where('type', 'steve')->orWhere('type', 'alex');
        } else {
            $textures = Texture::where('type', $filter);
        }

        $textures = $textures->orderBy($sort_by, 'desc')->get();

        if ($uid != 0) {
            $textures = $textures->where('uploader', $uid);
        }

        if (!is_null($this->user)) {
            // show private textures when show uploaded textures of current user
            if ($uid != $this->user->uid && !$this->user->isAdmin()) {
                $textures = $textures->where('public', 1)
                                     ->merge($textures->where('uploader', $this->user->uid));
            }
        } else {
            $textures = $textures->where('public', 1);
        }

        $total_pages = ceil($textures->count() / 20);

        $textures = $textures->slice(($page - 1) * 20);

        return view('skinlib.index')->with('user', $this->user)
                                    ->with('sort', $sort)
                                    ->with('filter', $filter)
                                    ->with('uploader', $uid)
                                    ->with('textures', $textures)
                                    ->with('page', $page)
                                    ->with('total_pages', $total_pages);
    }

    public function search(Request $request)
    {
        $q      = $request->input('q', '');
        $filter = $request->input('filter', 'skin');
        $sort   = $request->input('sort', 'time');

        $sort_by = ($sort == "time") ? "upload_at" : $sort;

        if ($q == '') {
            return redirect('skinlib');
        }

        if ($filter == "skin") {
            $textures = Texture::like('name', $q)->where(function($query) use ($q) {
                $query->where('type',   '=', 'steve')
                      ->orWhere('type', '=', 'alex');
            })->orderBy($sort_by, 'desc')->get();
        } else {
            $textures = Texture::like('name', $q)
                                ->where('type', $filter)
                                ->where('public', 1)
                                ->orderBy($sort_by, 'desc')->get();
        }

        if (!is_null($this->user)) {
            // show private textures when show uploaded textures of current user
            if (!$this->user->isAdmin()) {
                $textures = $textures->where('public', 1)
                                     ->merge($textures->where('uploader', $this->user->uid));
            }
        } else {
            $textures = $textures->where('public', 1);
        }

        return view('skinlib.search')->with('user', $this->user)
                                    ->with('sort', $sort)
                                    ->with('filter', $filter)
                                    ->with('uploader', 0)
                                    ->with('q', $q)
                                    ->with('textures', $textures);
    }

    public function show($tid)
    {
        $texture = Texture::find($tid);

        if (!$texture || $texture && !Storage::disk('textures')->has($texture->hash)) {
            if (Option::get('auto_del_invalid_texture') == "1") {
                if ($texture)
                    $texture->delete();

                abort(404, trans('skinlib.show.deleted'));
            }
            abort(404, trans('skinlib.show.deleted').trans('skinlib.show.contact-admin'));
        }

        if ($texture->public == "0") {
            if (is_null($this->user) || ($this->user->uid != $texture->uploader && !$this->user->isAdmin()))
                abort(404, trans('skinlib.show.private'));
        }

        return view('skinlib.show')->with('texture', $texture)->with('with_out_filter', true)->with('user', $this->user);
    }

    public function info($tid)
    {
        if ($t = Texture::find($tid)) {
            return json($t->toArray());
        } else {
            return json([]);
        }
    }

    public function upload()
    {
        return view('skinlib.upload')->with('user', $this->user)->with('with_out_filter', true);
    }

    public function handleUpload(Request $request)
    {
        if (($response = $this->checkUpload($request)) instanceof JsonResponse) {
            return $response;
        }

        $t            = new Texture();
        $t->name      = $request->input('name');
        $t->type      = $request->input('type');
        $t->likes     = 1;
        $t->hash      = Utils::upload($_FILES['file']);
        $t->size      = ceil($_FILES['file']['size'] / 1024);
        $t->public    = ($request->input('public') == 'true') ? "1" : "0";
        $t->uploader  = $this->user->uid;
        $t->upload_at = Utils::getTimeFormatted();

        $cost = $t->size * (($t->public == "1") ? Option::get('score_per_storage') : Option::get('private_score_per_storage'));
        $cost += option('score_per_closet_item');

        if ($this->user->getScore() < $cost)
            return json(trans('skinlib.upload.lack-score'), 7);

        $results = Texture::where('hash', $t->hash)->get();

        if (!$results->isEmpty()) {
            foreach ($results as $result) {
                // if the texture already uploaded was setted to private,
                // then allow to re-upload it.
                if ($result->type == $t->type && $result->public == "1") {
                    return json(trans('skinlib.upload.repeated'), 0, [
                        'tid' => $result->tid
                    ]);
                }
            }
        }

        $t->save();

        $this->user->setScore($cost, 'minus');

        if ($this->user->getCloset()->add($t->tid, $t->name)) {
            return json(trans('skinlib.upload.success', ['name' => $request->input('name')]), 0, [
                'tid'   => $t->tid
            ]);
        }
    }

    public function delete(Request $request, UserRepository $users)
    {
        $result = Texture::find($request->tid);

        if (!$result)
            return json(trans('skinlib.non-existent'), 1);

        if ($result->uploader != $this->user->uid && !$this->user->isAdmin())
            return json(trans('skinlib.no-permission'), 1);

        // check if file occupied
        if (Texture::where('hash', $result['hash'])->count() == 1)
            Storage::delete($result['hash']);

        if (option('return_score')) {
            if ($result->public == 1) {
                $users->get($result->uploader)->setScore($result->size * Option::get('score_per_storage'), 'plus');
                foreach (Closet::all() as $closet) {
                    if ($closet->has($result->tid)) {
                        $closet->remove($result->tid);
                        $users->get($closet->uid)->setScore(option('score_per_closet_item'), 'plus');
                    }
                }
            }
            else
                $users->get($result->uploader)->setScore($result->size * Option::get('private_score_per_storage'), 'plus');
        }

        if ($result->delete())
            return json(trans('skinlib.delete.success'), 0);
    }

    public function privacy(Request $request, UserRepository $users)
    {
        $t = Texture::find($request->input('tid'));
        $type = $t->type;
        $uid = session('uid');

        if (!$t)
            return json(trans('skinlib.non-existent'), 1);

        if ($t->uploader != $this->user->uid && !$this->user->isAdmin())
            return json(trans('skinlib.no-permission'), 1);

        foreach (Player::where("tid_$type", $t->tid)->where('uid', '<>', $uid)->get() as $player) {
            $player->setTexture(["tid_$type" => 0]);
        }

        foreach (Closet::all() as $closet) {
            if ($closet->uid != $uid && $closet->has($t->tid)) {
                $closet->remove($t->tid);
                if (option('return_score')) {
                    $users->get($closet->uid)->setScore(option('score_per_closet_item'), 'plus');
                }
            }
        }

        $users->get($t->uploader)->setScore(
            $t->size * (option('private_score_per_storage') - option('score_per_storage')) * ($t->public == 1 ? -1 : 1),
            'plus'
        );

        if ($t->setPrivacy(!$t->public)) {
            return json([
                'errno'  => 0,
                'msg'    => trans('skinlib.privacy.success', ['privacy' => ($t->public == "0" ? trans('general.private') : trans('general.public'))]),
                'public' => $t->public
            ]);
        }
    }

    public function rename(Request $request) {
        $this->validate($request, [
            'tid'      => 'required|integer',
            'new_name' => 'required|no_special_chars'
        ]);

        $t = Texture::find($request->input('tid'));

        if (!$t)
            return json(trans('skinlib.non-existent'), 1);

        if ($t->uploader != $this->user->uid && !$this->user->isAdmin())
            return json(trans('skinlib.no-permission'), 1);

        $t->name = $request->input('new_name');

        if ($t->save()) {
            return json(trans('skinlib.rename.success', ['name' => $request->input('new_name')]), 0);
        }
    }

    /**
     * Check Uploaded Files
     *
     * @param  Request $request
     * @return void
     */
    private function checkUpload(Request $request)
    {
        if ($file = $request->files->get('file')) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return json(Utils::convertUploadFileError($file->getError()), $file->getError());
            }
        }

        $this->validate($request, [
            'name'   => 'required|no_special_chars',
            'file'   => 'required|max:'.option('max_upload_file_size'),
            'public' => 'required'
        ]);

        if ($_FILES['file']['type'] != "image/png" && $_FILES['file']['type'] != "image/x-png") {
            return json(trans('skinlib.upload.type-error'), 1);
        }

        // if error occured while uploading file
        if ($_FILES['file']["error"] > 0)
            return json($_FILES['file']["error"], 1);

        $type  = $request->input('type');
        $size  = getimagesize($_FILES['file']["tmp_name"]);
        $ratio = $size[0] / $size[1];

        if ($type == "steve" || $type == "alex") {
            if ($ratio != 2 && $ratio != 1)
                return json(trans('skinlib.upload.invalid-size', ['type' => trans('general.skin'), 'width' => $size[0], 'height' => $size[1]]), 1);
            if ($size[0] % 64 != 0 || $size[1] % 32 != 0)
                return json(trans('skinlib.upload.invalid-hd-skin', ['type' => trans('general.skin'), 'width' => $size[0], 'height' => $size[1]]), 1);
        } elseif ($type == "cape") {
            if ($ratio != 2)
                return json(trans('skinlib.upload.invalid-size', ['type' => trans('general.cape'), 'width' => $size[0], 'height' => $size[1]]), 1);
        } else {
            return json(trans('general.illegal-parameters'), 1);
        }
    }

}
