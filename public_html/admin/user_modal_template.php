<!-- مودال کاربر -->
<div class="modal-overlay" id="userModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">افزودن کاربر</h3><span class="close-modal" onclick="closeModal()">&times;</span></div>
        
        <form method="POST" enctype="multipart/form-data" id="userForm" onsubmit="return validateForm(event)">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="userId">

            <div class="profile-upload">
                <img src="../assets/images/profile-icon.png" id="profilePreview">
                <div>
                    <label class="form-label" style="margin-bottom:5px;">تصویر پروفایل</label>
                    <input type="file" name="profile_image" accept="image/*" onchange="previewImage(this)">
                </div>
            </div>

            <!-- بقیه فیلدها (نام، نام خانوادگی، ...) دقیقاً مشابه کد قبلی -->
            <div class="row">
                <div class="col form-group"><label class="form-label">نام <span style="color:red">*</span></label><input type="text" name="first_name" id="firstName" class="form-control" required></div>
                <div class="col form-group"><label class="form-label">نام خانوادگی <span style="color:red">*</span></label><input type="text" name="last_name" id="lastName" class="form-control" required></div>
            </div>
            
            <div class="row">
                <div class="col form-group"><label class="form-label">نام کاربری <span style="color:red">*</span></label><input type="text" name="username" id="username" class="form-control" required></div>
                <div class="col form-group">
                    <label class="form-label">رمز عبور</label>
                    <input type="password" name="password" id="password" class="form-control">
                    <small id="passwordHelp" style="display:none;font-size:0.7rem;">فقط جهت تغییر وارد کنید.</small>
                </div>
            </div>

            <div class="divider"></div>

            <div class="row">
                <div class="col form-group"><label class="form-label">موبایل <span style="color:red">*</span></label><input type="text" name="mobile" id="mobile" class="form-control" required></div>
                <div class="col form-group"><label class="form-label">کد ملی</label><input type="text" name="national_code" id="nationalCode" class="form-control" maxlength="10"></div>
            </div>

            <div class="row">
                <div class="col form-group"><label class="form-label">کد پرسنلی <span style="color:red">*</span></label><input type="text" name="personnel_code" id="personnelCode" class="form-control" required></div>
                <div class="col form-group"><label class="form-label">تاریخ تولد</label><input type="text" name="birth_date" id="birthDate" class="form-control" autocomplete="off"></div>
            </div>

            <div class="row">
                <div class="col form-group"><label class="form-label">آدرس</label><input type="text" name="address" id="address" class="form-control"></div>
                <div class="col form-group"><label class="form-label">کد پستی</label><input type="text" name="postal_code" id="postalCode" class="form-control"></div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label class="form-label">دپارتمان <span style="color:red">*</span></label>
                    <select name="department_id" id="department" class="form-control" required>
                        <option value="">انتخاب...</option>
                        <?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col form-group">
                    <label class="form-label">نقش <span style="color:red">*</span></label>
                    <select name="role" id="role" class="form-control" required><option value="user">کاربر عادی</option><option value="admin">مدیر سیستم</option></select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">وضعیت</label>
                <select name="status" id="status" class="form-control"><option value="active">فعال</option><option value="inactive">غیرفعال</option></select>
            </div>

            <div style="text-align: left; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره اطلاعات</button>
            </div>
        </form>
    </div>
</div>