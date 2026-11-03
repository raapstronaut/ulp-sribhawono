import '../widgets/wo_status_chip.dart';

/// DATA CONTOH untuk mockup, bukan data operasional.
/// Nanti diganti respons API tanpa mengubah tampilan BerandaScreen.
class BerandaData {
  const BerandaData({
    required this.teamName,
    required this.shiftLabel,
    required this.shiftSchedule,
    required this.woTarget,
    required this.woDone,
    required this.woInProgress,
    required this.pendingSyncCount,
    required this.nextTask,
    required this.pasilaSummary,
    this.hasUnreadNotifications = false,
  });

  final String teamName;
  final String shiftLabel;
  final String shiftSchedule;
  final int woTarget;
  final int woDone;
  final int woInProgress;
  final int pendingSyncCount;
  final NextTask? nextTask;
  final String pasilaSummary;
  final bool hasUnreadNotifications;

  int get woNotStarted => woTarget - woDone - woInProgress;
  int get woRemaining => woTarget - woDone;
}

class NextTask {
  const NextTask({
    required this.code,
    required this.status,
    required this.title,
    required this.subtitle,
  });

  final String code;
  final WoStatus status;
  final String title;
  final String subtitle;
}

const mockBeranda = BerandaData(
  teamName: 'Sribhawono',
  shiftLabel: 'Shift A · Pagi',
  shiftSchedule: 'Sabtu, 24 Okt · 08:00–16:00',
  woTarget: 6,
  woDone: 2,
  woInProgress: 1,
  pendingSyncCount: 1,
  nextTask: NextTask(
    code: 'WO-APKUK-20261024-0003',
    status: WoStatus.dikerjakan,
    title: 'Tiang JP-045',
    subtitle: 'Penyulang JAIPONG · Kurang 3 APKUK',
  ),
  pasilaSummary: 'Sesi siap · 0 temuan',
  hasUnreadNotifications: true,
);
