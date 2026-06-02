<?php
/*
 * فایل: public_html/admin/crm_opp_modals.php
 * توضیحات: فایل نگهدارنده فرم‌های پاپ‌آپ (مودال) برای تب‌های فعالیت کانبان فروش
 */
?>

<!-- 📞 فرم ثبت/ویرایش تماس -->
<div id="callModal" class="modal-overlay" style="z-index:10005;">
    <div class="modal-content" style="max-width:500px; border-radius:12px;">
        <div class="modal-header">
            <h4 class="m-0 fw-bold" id="callModalTitle" style="font-size:1.1rem;">📞 ثبت تماس</h4>
            <span style="cursor:pointer; font-size:1.5rem; color:#ef4444;" onclick="closeModal('callModal')">&times;</span>
        </div>
        <form onsubmit="submitCallForm(event)">
            <input type="hidden" id="callOppId">
            <input type="hidden" id="editCallId" value="0">
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">موضوع تماس <span class="text-danger">*</span></label>
                    <input type="text" id="callSubject" class="form-control" required placeholder="مثال: پیگیری پیش فاکتور">
                </div>
                <div style="display:flex; gap:10px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label class="form-label">وضعیت <span class="text-danger">*</span></label>
                        <select id="callStatus" class="form-control" required>
                            <option value="برنامه ریزی شده" selected>برنامه ریزی شده</option>
                            <option value="تماس برقرار نشد">تماس برقرار نشد</option>
                            <option value="انجام شده">انجام شده</option>
                        </select>
                    </div>
                    <div style="flex:1;">
                        <label class="form-label">مرتبط با <span class="text-danger">*</span></label>
                        <select id="callRelatedTo" class="form-control" required>
                            <option value="حساب">حساب</option><option value="فرصت">فرصت</option><option value="سرنخ">سرنخ</option>
                            <option value="سرویس">سرویس</option><option value="پیش فاکتور">پیش فاکتور</option><option value="پرداخت">پرداخت</option><option value="فاکتور">فاکتور</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:10px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label class="form-label">نام مشتری <span class="text-danger">*</span></label>
                        <input type="text" id="callCustomerName" class="form-control" readonly required style="background:#f8fafc; color:#64748b;">
                    </div>
                    <div style="flex:1;">
                        <label class="form-label">تاریخ تماس <span class="text-danger">*</span></label>
                        <input type="text" id="callDate" class="form-control" required placeholder="140X/XX/XX" autocomplete="off">
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">نوع تماس <span class="text-danger">*</span></label>
                    <div style="display:flex; gap:15px; margin-top:5px; font-size:0.85rem;">
                        <label><input type="radio" name="callType" value="outbound" checked> 📤 خروجی</label>
                        <label><input type="radio" name="callType" value="inbound"> 📥 ورودی</label>
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">توضیحات</label>
                    <textarea id="callDesc" class="form-control" rows="2" placeholder="جزئیات مذاکره..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="padding:15px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc;">
                <button type="button" class="btn btn-outline" onclick="closeModal('callModal')">انصراف</button>
                <button type="submit" id="btnSubmitCall" class="btn btn-primary">💾 ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- 🚗 فرم ثبت مأموریت -->
<div id="missionModal" class="modal-overlay" style="z-index:10005;">
    <div class="modal-content" style="max-width:500px; border-radius:12px;">
        <div class="modal-header">
            <h4 class="m-0 fw-bold" style="font-size:1.1rem;">🚗 ثبت مأموریت جدید</h4>
            <span style="cursor:pointer; font-size:1.5rem; color:#ef4444;" onclick="closeModal('missionModal')">&times;</span>
        </div>
        <form onsubmit="submitMissionForm(event)">
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">موضوع مأموریت <span class="text-danger">*</span></label>
                    <input type="text" id="missionSubject" class="form-control" required placeholder="مثال: مراجعه حضوری برای عقد قرارداد">
                </div>
                <div style="display:flex; gap:10px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label class="form-label">تاریخ مراجعه <span class="text-danger">*</span></label>
                        <input type="text" id="missionDate" class="form-control" required placeholder="140X/XX/XX" autocomplete="off">
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">توضیحات / آدرس</label>
                    <textarea id="missionDesc" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="padding:15px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc;">
                <button type="button" class="btn btn-outline" onclick="closeModal('missionModal')">انصراف</button>
                <button type="submit" id="btnSubmitMission" class="btn btn-primary">💾 ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- 📝 فرم ثبت وظیفه -->
<div id="taskModal" class="modal-overlay" style="z-index:10005;">
    <div class="modal-content" style="max-width:500px; border-radius:12px;">
        <div class="modal-header">
            <h4 class="m-0 fw-bold" style="font-size:1.1rem;">📝 ثبت وظیفه جدید</h4>
            <span style="cursor:pointer; font-size:1.5rem; color:#ef4444;" onclick="closeModal('taskModal')">&times;</span>
        </div>
        <form onsubmit="submitTaskForm(event)">
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">عنوان وظیفه <span class="text-danger">*</span></label>
                    <input type="text" id="taskSubject" class="form-control" required placeholder="مثال: ارسال پیش فاکتور اصلاح شده">
                </div>
                <div style="display:flex; gap:10px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label class="form-label">مهلت انجام (Deadline) <span class="text-danger">*</span></label>
                        <input type="text" id="taskDate" class="form-control" required placeholder="140X/XX/XX" autocomplete="off">
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">شرح وظیفه</label>
                    <textarea id="taskDesc" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="padding:15px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc;">
                <button type="button" class="btn btn-outline" onclick="closeModal('taskModal')">انصراف</button>
                <button type="submit" id="btnSubmitTask" class="btn btn-primary">💾 ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- 📋 فرم ثبت یادداشت -->
<div id="noteModal" class="modal-overlay" style="z-index:10005;">
    <div class="modal-content" style="max-width:500px; border-radius:12px;">
        <div class="modal-header">
            <h4 class="m-0 fw-bold" style="font-size:1.1rem;">📋 ثبت یادداشت جدید</h4>
            <span style="cursor:pointer; font-size:1.5rem; color:#ef4444;" onclick="closeModal('noteModal')">&times;</span>
        </div>
        <form onsubmit="submitNoteForm(event)">
            <div class="modal-body">
                <div class="form-group mb-0">
                    <label class="form-label">متن یادداشت شخصی <span class="text-danger">*</span></label>
                    <textarea id="noteText" class="form-control" rows="5" required placeholder="نکات کلیدی مشتری..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="padding:15px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; background:#f8fafc;">
                <button type="button" class="btn btn-outline" onclick="closeModal('noteModal')">انصراف</button>
                <button type="submit" id="btnSubmitNote" class="btn btn-primary">💾 ذخیره</button>
            </div>
        </form>
    </div>
</div>