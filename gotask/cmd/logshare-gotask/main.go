// logshare-gotask 是 LogShare 的 MongoDB IPC 边车。
//
// 网络 I/O 由 Go 的官方 MongoDB 驱动执行，PHP 侧只负责 BSON 编解码和业务
// 逻辑。边车通过 hyperf/gotask 的 Unix socket 协议接收请求，生命周期由
// Hyperf 的 GoTaskProcess 管理。
package main

import (
	"context"
	"log"

	"github.com/hyperf/gotask/v2/pkg/gotask"
	"github.com/hyperf/gotask/v2/pkg/mongo_client"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

func main() {
	config := mongo_client.LoadConfig()

	connectCtx, cancel := context.WithTimeout(context.Background(), config.ConnectTimeout)
	defer cancel()

	client, err := mongo.Connect(connectCtx, options.Client().ApplyURI(config.Uri))
	if err != nil {
		log.Fatalf("连接 MongoDB 失败: %v", err)
	}
	defer func() {
		if err := client.Disconnect(context.Background()); err != nil {
			log.Printf("关闭 MongoDB 连接失败: %v", err)
		}
	}()

	if err := gotask.Register(mongo_client.NewMongoProxyWithTimeout(client, config.ReadWriteTimeout)); err != nil {
		log.Fatalf("注册 GoTask MongoDB 代理失败: %v", err)
	}

	if err := gotask.Run(); err != nil {
		log.Fatalf("GoTask 边车退出: %v", err)
	}
}
