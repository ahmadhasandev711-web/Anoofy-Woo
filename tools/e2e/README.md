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
6. فحص وصول حاويات الشبكة الأخرى (عبر `curlimages/curl`) إلى `anoofy-wp.test` والتحقق من أن الـ IP يقع ضمن الـ subnet الخاص بشبكة `engaz-e2e`.
7. التحقق من إمكانية الوصول إلى `engazpro.test` وأن الـ IP يقع ضمن الـ subnet الخاص بشبكة `engaz-e2e`، وطباعة تحذير (`WARN: EngazPRO مش على شبكة engaz-e2e`) في حال لم يكن كونتينر EngazPRO قيد التشغيل بعد أو كان يُرجع `127.0.0.1`.

> [!IMPORTANT]
> **ملاحظة حول دقة فحص النطاقات (DNS Resolution في Docker Desktop):**
> يقوم Docker Desktop بتوجيه استعلامات DNS إلى ملف `hosts` الخاص بنظام Windows في حال عدم العثور على اسم النطاق داخل شبكة Docker الداخلية. يترتب على ذلك أن فحص مثل `getent hosts engazpro.test` سيعيد عنوان الـ loopback الخاص بالكونتينر (`127.0.0.1`) بدلاً من الفشل عندما لا يكون كونتينر EngazPRO قيد التشغيل بعد على الشبكة.
> لذلك، يعتبر الفحص ناجحاً (`PASS`) فقط عندما يكون عنوان الـ IP المسترجع يقع فعلياً داخل الـ subnet الخاص بشبكة `engaz-e2e` (عبر `docker network inspect engaz-e2e`). وإذا كان العنوان `127.0.0.1` أو خارج الـ subnet، يُظهر السكربت تنبيهاً تحذيرياً (`WARN: EngazPRO مش على شبكة engaz-e2e`).

