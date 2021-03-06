<?php

/*
 * +----------------------------------------------------------------------+
 * |                          ThinkSNS Plus                               |
 * +----------------------------------------------------------------------+
 * | Copyright (c) 2017 Chengdu ZhiYiChuangXiang Technology Co., Ltd.     |
 * +----------------------------------------------------------------------+
 * | This source file is subject to version 2.0 of the Apache license,    |
 * | that is bundled with this package in the file LICENSE, and is        |
 * | available through the world-wide-web at the following url:           |
 * | http://www.apache.org/licenses/LICENSE-2.0.html                      |
 * +----------------------------------------------------------------------+
 * | Author: Slim Kit Group <master@zhiyicx.com>                          |
 * | Homepage: www.thinksns.com                                           |
 * +----------------------------------------------------------------------+
 */

namespace Zhiyi\PlusGroup\API\Controllers;

use DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Zhiyi\PlusGroup\Models\GroupMember;
use Zhiyi\Plus\Http\Controllers\Controller;
use Zhiyi\Plus\Models\Report as ReportModel;
use Zhiyi\PlusGroup\Models\Post as PostModel;
use Zhiyi\Plus\Models\Comment as CommentModel;
use Illuminate\Validation\ValidationException;
use Zhiyi\Plus\Notifications\System as SystemNotification;
use Zhiyi\PlusGroup\Models\GroupReport as GroupReportModel;

class GroupReportController extends Controller
{
    /**
     * 举报列表.
     *
     * @param  Request  $request
     *
     * @return mixed
     */
    public function reports(Request $request)
    {
        $end = $request->query('end');
        $start = $request->query('start');
        $status = $request->query('status');
        $groupId = (int) $request->query('group_id');

        $limit = (int) $request->query('limit', 15);
        $after = (int) $request->query('after', 0);

        $member = GroupMember::select('role')->where('group_id', $groupId)
            ->where('user_id', $request->user()->id)->first();

        if (is_null($member)
            || ! in_array($member->role, ['founder', 'administrator'])
        ) {
            return response()->json(['message' => '无权限访问'], 403);
        }

        $reports = GroupReportModel::with([
            'user' => function ($query) {
                $query->withTrashed();
            },
            'target',
        ])
            ->when(! is_null($status), function ($query) use ($status) {
                return $query->where('status', $status);
            })
            ->when($after, function ($query) use ($after) {
                return $query->where('id', '<', $after);
            })
            ->when($start, function ($query) use ($start) {
                return $query->where('created_at', '>=',
                    Carbon::createFromTimestamp($start));
            })
            ->when($end, function ($query) use ($end) {
                return $query->where('created_at', '<',
                    Carbon::createFromTimestamp($end));
            })
            ->where('group_id', $groupId)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $items = $reports->map(function ($item) {
            $item->resource = $item->resource;

            return $item;
        });

        return response()->json($items, 200);
    }

    /**
     * 处理帖子举报.
     *
     * @param  Request  $request
     * @param  PostModel  $post
     *
     * @return mixed
     * @throws ValidationException
     */
    public function postReport(Request $request, PostModel $post)
    {
        $this->validateReport($request);
        $user = $request->user();

        if (config('plus-group.report_handle') == 'admin') {
            $reportModel = new ReportModel();
            $reportModel->user_id = $user->id;
            $reportModel->target_user = $post->user_id;
            $reportModel->status = 0;
            $reportModel->reason = $request->input('content');
            $reportModel->subject = sprintf('圈子帖子：%s',
                empty($post->body) ? $post->id : mb_substr($post->body, 0, 50));
            $post->reports()->save($reportModel);
        } else {
            $report = new GroupReportModel();
            $report->user_id = $user->id;
            $report->target_id = $post->user_id;
            $report->group_id = $post->group_id;
            $report->resource_id = $post->id;
            $report->content = $request->input('content');
            $report->type = 'post';
            $report->status = 0;
            $report->save();

            $post->group->members()
                ->whereIn('role', ['administrator', 'founder'])
                ->where('audit', 1)
                ->get()
                ->map(function ($member) use ($post, $user) {
                    $member->user->notify(new SystemNotification('你管理的圈子有新的举报，请尽快处理！',
                        [
                            'type'   => 'group:report-post',
                            'sender' => [
                                'id'   => $user->id,
                                'name' => $user->name,
                            ],
                            'group'  => [
                                'id'   => $post->group->id,
                                'name' => $post->group->name,
                            ],
                            'post'   => [
                                'id'    => $post->id,
                                'title' => $post->title,
                            ],
                        ]));
                });
        }

        return response()->json(['message' => '举报成功'], 201);
    }

    /**
     * 处理评论举报.
     *
     * @param  Request  $request
     * @param  CommentModel  $comment
     *
     * @param  PostModel  $postModel
     *
     * @return mixed
     * @throws ValidationException
     */
    public function commentReport(
        Request $request,
        CommentModel $comment,
        PostModel $postModel
    ) {
        $this->validateReport($request);

        if ($comment->commentable_type != 'group-posts') {
            return response()->json(['message' => '举报资源错误'], 422);
        }

        $user = $request->user();

        if (config('plus-group.report_handle') == 'admin') {
            $reportModel = new ReportModel();
            $reportModel->user_id = $user->id;
            $reportModel->target_user = $comment->user_id;
            $reportModel->status = 0;
            $reportModel->reason = $request->input('content');
            $reportModel->subject = sprintf('圈子帖子评论：%s', empty($comment->body)
                ? $comment->id : mb_substr($comment->body, 0, 50));
            $comment->reports()->save($reportModel);
        } else {
            $post = $postModel->find($comment->commentable_id);

            $report = new GroupReportModel();
            $report->user_id = $user->id;
            $report->target_id = $comment->user_id;
            $report->group_id = $post->group_id;
            $report->resource_id = $comment->id;
            $report->content = $request->input('content');
            $report->type = 'comment';
            $report->status = 0;
            $report->save();

            $post->group->members()
                ->whereIn('role', ['administrator', 'founder'])
                ->where('audit', 1)
                ->get()
                ->map(function ($member) use ($comment, $post, $user) {
                    $member->user->notify(new SystemNotification('你管理的圈子有新的举报，请尽快处理！',
                        [
                            'type'    => 'group:report-comment',
                            'sender'  => [
                                'id'   => $user->id,
                                'name' => $user->name,
                            ],
                            'group'   => [
                                'id'   => $post->group->id,
                                'name' => $post->group->name,
                            ],
                            'post'    => [
                                'id'    => $post->id,
                                'title' => $post->title,
                            ],
                            'comment' => [
                                'id'       => $comment->id,
                                'contents' => $comment->body,
                            ],
                        ]));
                });
        }

        return response()->json(['message' => '举报成功'], 201);
    }

    /**
     * 举报通过审核.
     *
     * @param  Request  $request
     * @param  GroupReportModel  $report
     *
     * @return mixed
     */
    public function accept(Request $request, GroupReportModel $report)
    {
        if (in_array($report->status, [1, 2])) {
            return response()->json(['message' => '举报已处理'], 422);
        }

        if (! $report->resource) {
            return response()->json(['message' => '被举报资源不存在或已删除'], 404);
        }

        $report->status = 1;
        $report->handler = $request->user()->id;
        $report->cause = $request->input('cause');
        $report->save();

        if ($report->type == 'post') {
            $message = sprintf('您对帖子《%s》的举报已被管理员处理', $report->resource->title);
        } else {
            $message = sprintf('您对评论《%s》的举报已被管理员处理',
                mb_substr($report->resource->body, 0, 50));
        }

        if ($report->user) {
            $report->user->notify(new SystemNotification($message, [
                'group_id' => $report->group_id,
                'report'   => $report->content,
                'type'     => 'group:report',
                'state'    => 'passed',
            ]));
        }

        return response()->json(['message' => '操作成功'], 201);
    }

    /**
     * 处理举报审核.
     *
     * @param  Request  $request
     * @param  GroupReportModel  $report
     *
     * @return mixed
     */
    public function reject(Request $request, GroupReportModel $report)
    {
        if (in_array($report->status, [1, 2])) {
            return response()->json(['message' => '举报已处理'], 422);
        }

        $report->status = 2;
        $report->handler = $request->user()->id;
        $report->cause = $request->input('cause');
         $report->save();

        if ($report->type == 'post') {
            $message = sprintf('您对帖子《%s》的举报已被管理员驳回', $report->resource->title);
        } else {
            $message = sprintf('您对评论《%s》的举报已被管理员驳回',
                mb_substr($report->resource->body, 0, 50));
        }
        if ($report->user) {
            $report->user->notify(new SystemNotification($message, [
                'group_id' => $report->group_id,
                'report'   => $report->content,
                'type'     => 'group:report',
                'state'    => 'rejected',
            ]));
        }

        return response()->json(['message' => '操作成功'], 201);
    }

    /**
     * 验证举报.
     *
     * @param  Request  $request
     *
     * @return mixed
     * @throws ValidationException
     */
    private function validateReport(Request $request)
    {
        return $this->validate($request, [
            'content' => 'required|max:255',
        ], [
            'content.required' => '请填写举报内容',
            'content.max'      => '举报内容长度不能255',
        ]);
    }
}
