// 首次创建数据卷时执行：为应用创建最小权限用户。
// dbAdmin 用于 createIndex / collMod（TTL 变更）；不授予全局 root。
const appDb = process.env.MONGO_INITDB_DATABASE || 'logshare';
const appUser = process.env.MONGO_APP_USERNAME || 'logshare';
const appPassword = process.env.MONGO_APP_PASSWORD || '';

if (!appPassword) {
  throw new Error('MONGO_APP_PASSWORD is required');
}

const target = db.getSiblingDB(appDb);
if (target.getUser(appUser) === null) {
  target.createUser({
    user: appUser,
    pwd: appPassword,
    roles: [
      { role: 'readWrite', db: appDb },
      { role: 'dbAdmin', db: appDb },
    ],
  });
}
