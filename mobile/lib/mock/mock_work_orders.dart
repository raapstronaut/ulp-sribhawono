import '../widgets/wo_status_chip.dart';

/// DATA CONTOH untuk mockup, bukan data operasional.
class WorkOrderItem {
  const WorkOrderItem({
    required this.routeOrder,
    required this.pole,
    required this.feeder,
    required this.section,
    required this.previousPole,
    required this.reportedInstalled,
    required this.missingApkuk,
    required this.latitude,
    required this.longitude,
    required this.status,
    required this.code,
  });

  final int routeOrder;
  final String pole;
  final String feeder;
  final String section;
  final String previousPole;
  final int reportedInstalled;
  final int missingApkuk;
  final double latitude;
  final double longitude;
  final WoStatus status;
  final String code;

  String get coordinates =>
      '${latitude.toStringAsFixed(5)}, ${longitude.toStringAsFixed(5)}';

  /// Riwayat contoh, terbaru di atas.
  List<WoHistoryEntry> get history => [
    if (status == WoStatus.selesai)
      const WoHistoryEntry(
        status: WoStatus.selesai,
        title: 'Laporan selesai',
        detail: '09:48 · Tim POSKO Sribhawono',
      ),
    if (status == WoStatus.selesai || status == WoStatus.dikerjakan)
      const WoHistoryEntry(
        status: WoStatus.dikerjakan,
        title: 'Mulai dikerjakan',
        detail: '09:12 · Tim POSKO Sribhawono',
      ),
    const WoHistoryEntry(
      status: WoStatus.ditugaskan,
      title: 'Ditugaskan',
      detail: '08:05 · Admin',
    ),
  ];
}

class WoHistoryEntry {
  const WoHistoryEntry({
    required this.status,
    required this.title,
    required this.detail,
  });

  final WoStatus status;
  final String title;
  final String detail;
}

const mockShiftName = 'Shift A';
const mockShiftSubtitle = '$mockShiftName · Sabtu, 24 Okt';
const mockDueTime = '16:00 WIB';

const mockWorkOrders = [
  WorkOrderItem(
    routeOrder: 1,
    pole: 'JP-041',
    feeder: 'JAIPONG',
    section: 'REC JABO',
    previousPole: 'JP-040',
    reportedInstalled: 3,
    missingApkuk: 2,
    latitude: -5.20921,
    longitude: 105.45812,
    status: WoStatus.selesai,
    code: 'WO-APKUK-20261024-0001',
  ),
  WorkOrderItem(
    routeOrder: 2,
    pole: 'JP-043',
    feeder: 'JAIPONG',
    section: 'REC JABO',
    previousPole: 'JP-042',
    reportedInstalled: 1,
    missingApkuk: 4,
    latitude: -5.21013,
    longitude: 105.45915,
    status: WoStatus.selesai,
    code: 'WO-APKUK-20261024-0002',
  ),
  WorkOrderItem(
    routeOrder: 3,
    pole: 'JP-045',
    feeder: 'JAIPONG',
    section: 'REC JABO',
    previousPole: 'JP-044',
    reportedInstalled: 2,
    missingApkuk: 3,
    latitude: -5.21099,
    longitude: 105.46007,
    status: WoStatus.dikerjakan,
    code: 'WO-APKUK-20261024-0003',
  ),
  WorkOrderItem(
    routeOrder: 4,
    pole: 'JP-052',
    feeder: 'JAIPONG',
    section: 'REC JABO',
    previousPole: 'JP-051',
    reportedInstalled: 4,
    missingApkuk: 1,
    latitude: -5.21342,
    longitude: 105.46288,
    status: WoStatus.ditugaskan,
    code: 'WO-APKUK-20261024-0004',
  ),
  WorkOrderItem(
    routeOrder: 5,
    pole: 'JP-058',
    feeder: 'JAIPONG',
    section: 'LBSM KARYA TANI',
    previousPole: 'JP-057',
    reportedInstalled: 3,
    missingApkuk: 2,
    latitude: -5.21587,
    longitude: 105.46503,
    status: WoStatus.ditugaskan,
    code: 'WO-APKUK-20261024-0005',
  ),
  WorkOrderItem(
    routeOrder: 6,
    pole: 'JT-012',
    feeder: 'JATILAN',
    section: 'LBSM ZAPTOR',
    previousPole: 'JT-011',
    reportedInstalled: 0,
    missingApkuk: 5,
    latitude: -5.22416,
    longitude: 105.47134,
    status: WoStatus.ditugaskan,
    code: 'WO-APKUK-20261024-0006',
  ),
];
