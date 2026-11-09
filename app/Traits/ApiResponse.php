<?php 

namespace App\Traits;

trait ApiResponse
{
    /**
     * 成功响应
     */
    public function success($data = [], $message = "操作成功", $code = 200)
    {
        return response()->json([
            'status'  => 0,      // 业务状态码：0 成功，1 失败
            'code'    => $code,   // HTTP 状态码
            'msg'     => $message,
            'data'    => $data
        ], $code);
    }

    /**
     * 失败响应
     */
    public function error($message = "操作失败", $code = 400, $data = [])
    {
        return response()->json([
            'status'  => 1,
            'code'    => $code,
            'msg'     => $message,
            'data'    => $data
        ], $code);
    }
}