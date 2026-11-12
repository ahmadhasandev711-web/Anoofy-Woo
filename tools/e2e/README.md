# بيئة التطوير والتكامل المحلي (E2E Dev Network)

هذا الدليل يشرح كيفية إعداد وتشغيل بيئة E2E المحلية لربط إضافة **Anoofy-Woo** مع نظام **EngazPRO** عبر Docker دون الحاجة لـ HTTPS خارجي أو تعديل شهادات SSL.

---

## 1. المتطلبات الأساسية

1. **Docker Compose v2.24 أو أحدث:**
   ملف الإعداد `docker-compose.e2e.override.yml` يستخدم خاصية `ports: !override` لاستبدال المنافذ الافتراضية، وهذه الخاصية تتطلب إصدار Docker Compose 2.24.0 كحد أدنى.
   يمكنك التحقق من الإصدار عبر:
   ```bash
   docker compose version
   ```

2. **تعديل ملف الـ hosts في نظام Windows:**
   يجب إضافة السطرين التاليين يدوياً في ملف `C:\Windows\System32\drivers\etc\hosts`:
   ```text
   127.0.0.1 anoofy-wp.test
   127.0.0.1 engazpro.test
   ```
   *ملاحظة: السكربتات التلقائية لا تعدل ملف الـ hosts بنفسها، بل تتحقق من وجود السطرين وتنبّهك في حال غيابهما.*

---

## 2. هيكل البيئة والشبكة المشتركة

- **الشبكة المشتركة (`engaz-e2e`):**
  شبكة Docker خارجية موحدة تجمع بين حاويات ووردبريس وحاويات EngazPRO:
  ```bash
  docker network create engaz-e2e
  ```
- **عناوين النطاقات الثابتة داخل الشبكة وخارجها:**
  - المتجر (WordPress + WooCommerce): `http://anoofy-wp.test:28080`
  - نظام إنجاز برو (EngazPRO): `http://engazpro.test:28180` (أو المنفذ المخصص له)
- **منفذ Apache الموحد (28080):**
  يقوم ملف `anoofy-e2e-ports.conf` بتوجيه Apache داخل الكونتينر للاستماع على المنفذ `28080` بجانب المنفذ `80`، لتتطابق الروابط بين جهاز المضيف (Host) وداخل شبكة الحاويات (Docker Network).
- **إضافة الـ MU الخاصة بالتطوير (`anoofy-e2e-dev.mu.php`):**
  - مخصصة لبيئة التطوير فقط (`WP_ENVIRONMENT_TYPE === 'local'`).
  - تسمح لـ WooCommerce بقبول مصادقة Basic Auth عبر HTTP لطلبات EngazPRO على `/wp-json/wc/`.
  - تسمح لطلبات HTTP الخارجية الصادرة بالاتصال بـ `engazpro.test`.

---

## 3. تشغيل البيئة وفحصها

لتشغيل وفحص بيئة الـ E2E بالكامل، استخدم السكربت الجاهز:
```powershell
powershell -ExecutionPolicy Bypass -File tools/run-e2e-env.ps1
```

يقوم السكربت بما يلي:
1. التحقق من إصدار Docker Compose (≥ 2.24).
2. التحقق من وجود نطاقي `anoofy-wp.test` و `engazpro.test` في ملف `hosts`.
3. إنشاء شبكة `engaz-e2e` إذا لم تكن موجودة.
4. تشغيل ووردبريس مع ملف التخصيص `docker-compose.e2e.override.yml`.
5. فحص استجابة المتجر بـ HTTP 200 من الجهاز المضيف ومن داخل كونتينر ووردبريس.
6. التحقق من إمكانية الوصول إلى `engazpro.test` وطباعة تحذير في حال لم يكن كونتينر EngazPRO قيد التشغيل بعد.
