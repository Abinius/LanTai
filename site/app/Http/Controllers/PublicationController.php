<?php

namespace App\Http\Controllers;

use App\Services\PublicationService;
use Illuminate\Http\Request;

/**
 * 情报流与报告详情。
 *
 * 数据源为内核产物 JSON（PRD §1：站读取内核产出渲染），无写操作。
 */
class PublicationController extends Controller
{
    public function __construct(private PublicationService $publications)
    {
    }

    /** 情报流：报告列表 + 全文检索 */
    public function index(Request $request)
    {
        return view('publications.index', [
            'publications' => $this->publications->paginate($request),
            'q' => (string) $request->query('q', ''),
            'periods' => $this->publications->periods(),
        ]);
    }

    /** 报告详情 */
    public function show(string $period)
    {
        $data = $this->publications->show($period);

        abort_if($data === null, 404);

        return view('publications.show', $data);
    }
}
