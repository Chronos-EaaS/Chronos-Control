<?php
declare(strict_types=1);

namespace Chronos\Api\V3;

/** Authenticated descriptors and data pages, with no PHP UI or asset execution. */
final class ExtensionRoutes
{
    public function __construct(private readonly Request $request,private readonly Store $db,private readonly array $actor,private readonly string $root,private readonly Sessions $sessions) {}

    public function handle(string $path): ?array
    {
        if(!preg_match('#^/systems/([1-9][0-9]*)/(extensions|pages/([a-z][a-z0-9-]{0,63}))$#D',$path,$match))return null;
        $this->request->assertMethod(['GET']);
        $id=Input::id($match[1],'systemId');
        $system=$this->db->row('SELECT systemId,userId FROM `System` WHERE systemId=?',[$id])??throw new HttpError(404,'not_found','The system does not exist.');
        $owner=Policy::system($this->actor,$system);
        $member=$owner||$this->db->row('SELECT p.projectId FROM `Project` p WHERE p.systemId=? AND (p.userId=? OR EXISTS (SELECT 1 FROM `ProjectUser` member WHERE member.projectId=p.projectId AND member.userId=?)) LIMIT 1',[$id,$this->actor['id'],$this->actor['id']])!==null;
        Policy::require($member);
        $extensions=new Extensions($this->root,$id);
        if($match[2]==='extensions')return Context::success($extensions->catalog($owner));
        return Context::success($extensions->page($match[3],$owner,['systemId'=>$id,'userId'=>(int)$this->actor['id'],'role'=>(int)$this->actor['role']]));
    }
}
