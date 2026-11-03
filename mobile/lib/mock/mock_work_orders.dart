import '../widgets/wo_status_chip.dart';

/// DATA CONTOH untuk mockup, bukan data operasional.
class WorkOrderItem {
  const WorkOrderItem({
    required this.routeOrder,
    required this.pole,
    required this.feeder,
    required this.missingApkuk,
    required this.status,
    required this.code,
  });

  final int routeOrder;
  final String pole;
  final String feeder;
  final int missingApkuk;
  final WoStatus status;
  final String code;
}

const mockShiftSubtitle = 'Shift A · Sabtu, 24 Okt';

const mockWorkOrders = [
  WorkOrderItem(
    routeOrder: 1,
    pole: 'JP-041',
    feeder: 'JAIPONG',
    missingApkuk: 2,
    status: WoStatus.selesai,
    code: 'WO-APKUK-20261024-0001',
  ),
  WorkOrderItem(
    routeOrder: 2,
    pole: 'JP-043',
    feeder: 'JAIPONG',
    missingApkuk: 4,
    status: WoStatus.selesai,
    code: 'WO-APKUK-20261024-0002',
  ),
  WorkOrderItem(
    routeOrder: 3,
    pole: 'JP-045',
    feeder: 'JAIPONG',
    missingApkuk: 3,
    status: WoStatus.dikerjakan,
    code: 'WO-APKUK-20261024-0003',
  ),
  WorkOrderItem(
    routeOrder: 4,
    pole: 'JP-052',
    feeder: 'JAIPONG',
    missingApkuk: 1,
    status: WoStatus.ditugaskan,
    code: 'WO-APKUK-20261024-0004',
  ),
  WorkOrderItem(
    routeOrder: 5,
    pole: 'JP-058',
    feeder: 'JAIPONG',
    missingApkuk: 2,
    status: WoStatus.ditugaskan,
    code: 'WO-APKUK-20261024-0005',
  ),
  WorkOrderItem(
    routeOrder: 6,
    pole: 'JT-012',
    feeder: 'JATILAN',
    missingApkuk: 5,
    status: WoStatus.ditugaskan,
    code: 'WO-APKUK-20261024-0006',
  ),
];
