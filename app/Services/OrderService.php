<?php

namespace App\Services;

use App\Exceptions\CouponCodeUnavailableException;
use App\Exceptions\InvalidRequestException;
use App\Http\Requests\OrderRequest;
use App\Jobs\CloseOrder;
use App\Models\CouponCode;
use App\Models\Order;
use App\Models\ProductSku;
use App\Models\User;
use App\Models\UserAddress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function store(User $user,UserAddress $address,$remark,$items,CouponCode $coupon = null)
    {
        // 检查优惠券是否可用
        if($coupon){
            $coupon->checkAvailable($user);
        }

        // 开启一个数据库事务
        $order = DB::transaction(function () use ($user,$address,$remark,$items,$coupon){
            // 更新此地址的最后使用时间
            $address->update(['last_used_at' => Carbon::now()]);
            // 创建一个订单
            $order = new Order([
                'address' => [ // 将地址信息放入订单中
                    'address' => $address->full_address,
                    'zip' => $address->zip,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone,
                ],
                'remark' => $remark,
                'total_amount' => 0
            ]);
            // 订单关联当前用户
            $order->user()->associate($user);

            // 写入数据库
            $order->save();

            $totalAmount = 0;
            // 遍历用户提交的 SKU
            foreach ($items as $data){
                $sku = ProductSku::find($data['sku_id']);
                // 创建一个 orderItem 并直接与当前订单关联
                $item = $order->items()->make([
                    'amount' => $data['amount'],
                    'price' => $sku->price,
                ]);
                $item->product()->associate($sku->product_id);
                $item->productSku()->associate($sku);
                $item->save();
                $totalAmount += $sku->price * $data['amount'];
                if($sku->decreaseStock($data['amount']) <= 0){
                    throw new InvalidRequestException('该商品库存不足');
                }
            }

            if($coupon){
                $coupon->checkAvailable($user,$totalAmount);
                $totalAmount = $coupon->getAdjustedPrice($totalAmount); // 把订单金额修改成优惠后的金额
                $order->couponCode()->associate($coupon); // 将订单与优惠券关联
                // 增加优惠券的用量，需判断返回值
                if($coupon->changeUsed() <= 0){
                    throw new CouponCodeUnavailableException('该优惠券已被兑完');
                }
            }

            // 更新订单总额
            $order->update(['total_amount' => $totalAmount]);

            // 将下单的商品从购物车中移除
            $skuIds = collect($items)->pluck('sku_id')->all();
            app(CartService::class)->remove($skuIds);

            return $order;
        });

        dispatch(new CloseOrder($order,config('app.order_ttl')));

        return $order;
    }
}
